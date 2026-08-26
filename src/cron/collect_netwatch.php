<?php

declare(strict_types=1);

/**
 * Mikrotik Watch - Cron de Coleta e Sincronização do Netwatch
 *
 * Script executado via crontab para sincronizar e atualizar o status dos
 * hosts monitorados via Netwatch nos equipamentos Mikrotik.
 *
 * Execução:
 *   cd /var/www/Mikrotik\ Watch/src && php cron/collect_netwatch.php
 *
 * Crontab (a cada 1 minuto):
 *   * * * * * cd /var/www/Mikrotik\ Watch/src && php cron/collect_netwatch.php >> /var/log/mikrotik-watch/cron.log 2>&1
 *
 * Fluxo:
 *   1. Adquire lock (cron_locks, job 'netwatch_sync')
 *   2. Para cada Mikrotik ativo:
 *      a. Conecta via MikrotikClient e chama netwatch()
 *      b. Sincroniza hosts: insere novos, desativa ausentes
 *      c. Atualiza status de cada host (up/down)
 *      d. Registra eventos de transição (netwatch_events)
 *   3. Atualiza status do Mikrotik (online/offline)
 *   4. Libera lock
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';

use App\Service\MikrotikClient;
use App\Service\CredentialCrypto;
use App\Exception\MikrotikApiException;

// Configurar timezone
date_default_timezone_set($config['app']['timezone']);

$logFile = '/var/log/mikrotik-watch/cron.log';
$jobName = 'netwatch_sync';

// ─── Helpers ─────────────────────────────────────────────────────────────────

function logMessage(string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] {$message}" . PHP_EOL;
    echo $line;

    @file_put_contents($GLOBALS['logFile'], $line, FILE_APPEND);
}

function getDb(array $config): PDO
{
    $db = $config['database'];
    $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}";

    return new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function acquireLock(PDO $db, string $jobName): bool
{
    $stmt = $db->prepare('
        UPDATE cron_locks
        SET locked_at = now(), released_at = NULL
        WHERE job_name = :job
          AND (locked_at IS NULL OR released_at IS NOT NULL
               OR locked_at < now() - INTERVAL \'15 minutes\')
    ');
    $stmt->execute([':job' => $jobName]);
    return $stmt->rowCount() > 0;
}

function releaseLock(PDO $db, string $jobName): void
{
    $stmt = $db->prepare('UPDATE cron_locks SET released_at = now() WHERE job_name = :job');
    $stmt->execute([':job' => $jobName]);
}

// ─── Início ──────────────────────────────────────────────────────────────────

logMessage("=== Início da coleta Netwatch ===");

try {
    $db = getDb($config);

    // ─── Adquirir lock ────────────────────────────────────────────────────────

    if (!acquireLock($db, $jobName)) {
        logMessage("Lock não adquirido. Outro ciclo ainda está em execução. Abortando.");
        exit(0);
    }

    $lockAcquired = true;
    logMessage("Lock adquirido para '{$jobName}'.");

    // ─── Criar crypto para descriptografar senhas ─────────────────────────────

    $crypto = new CredentialCrypto($config['mikrotik']['credential_key']);

    // ─── Listar Mikrotiks ativos ──────────────────────────────────────────────

    $stmt = $db->query('
        SELECT id, name, host, port, use_ssl, username, password_encrypted, current_status
        FROM mikrotiks
        WHERE active = true
        ORDER BY name
    ');
    $mikrotiks = $stmt->fetchAll();

    if (empty($mikrotiks)) {
        logMessage("Nenhum equipamento Mikrotik ativo encontrado.");
        releaseLock($db, $jobName);
        exit(0);
    }

    logMessage("Equipamentos ativos: " . count($mikrotiks));

    // ─── Processar cada Mikrotik ──────────────────────────────────────────────

    $stats = [
        'processed'  => 0,
        'synced'     => 0,
        'inserted'   => 0,
        'deactivated' => 0,
        'errors'     => 0,
    ];

    foreach ($mikrotiks as $mikrotik) {
        $mikrotikId = $mikrotik['id'];
        $mikrotikName = $mikrotik['name'];

        try {
            // Descriptografar senha (PDO retorna BYTEA como stream)
            $pwRaw = $mikrotik['password_encrypted'];
            if (is_resource($pwRaw)) {
                $pwRaw = stream_get_contents($pwRaw);
            }
            $encryptedBase64 = base64_encode($pwRaw);
            $password = $crypto->decrypt($encryptedBase64);

            // Criar cliente Mikrotik
            $client = new MikrotikClient(
                host: $mikrotik['host'],
                username: $mikrotik['username'],
                password: $password,
                port: (int) $mikrotik['port'],
                useSsl: (bool) $mikrotik['use_ssl'],
                verifySsl: !$config['mikrotik']['allow_self_signed'],
                timeout: $config['mikrotik']['default_timeout'],
            );

            // Chamar API netwatch
            $apiHosts = $client->netwatch();
            $mikrotikNewStatus = 'online';

            logMessage("[{$mikrotikName}] Conectado. Hosts na API: " . count($apiHosts));

            // ─── Sincronizar hosts ────────────────────────────────────────────

            // Buscar hosts existentes no banco
            $stmt = $db->prepare('
                SELECT id, host_address, mikrotik_ref_id, current_status, status_since
                FROM netwatch_hosts
                WHERE mikrotik_id = :mikrotik_id
            ');
            $stmt->execute([':mikrotik_id' => $mikrotikId]);
            $existingHosts = $stmt->fetchAll();

            // Indexar existentes por mikrotik_ref_id
            $existingByRef = [];
            foreach ($existingHosts as $existing) {
                if ($existing['mikrotik_ref_id'] !== null) {
                    $existingByRef[$existing['mikrotik_ref_id']] = $existing;
                }
            }

            // Indexar API hosts por .id
            $apiByRef = [];
            foreach ($apiHosts as $apiHost) {
                $refId = $apiHost['.id'] ?? null;
                if ($refId !== null) {
                    $apiByRef[$refId] = $apiHost;
                }
            }

            // 1. Hosts novos na API → inserir no banco
            foreach ($apiByRef as $refId => $apiHost) {
                if (!isset($existingByRef[$refId])) {
                    try {
                        $hostAddress = $apiHost['host'] ?? '';
                        $comment = $apiHost['comment'] ?? null;
                        $apiStatus = $apiHost['status'] ?? 'unknown';
                        $status = ($apiStatus === 'up') ? 'up' : (($apiStatus === 'down') ? 'down' : 'unknown');

                        $stmt = $db->prepare('
                            INSERT INTO netwatch_hosts (mikrotik_id, host_address, comment, mikrotik_ref_id, current_status, status_since, last_checked_at, active)
                            VALUES (:mikrotik_id, :host_address, :comment, :mikrotik_ref_id, :status, now(), now(), true)
                            RETURNING id
                        ');
                        $stmt->execute([
                            ':mikrotik_id'     => $mikrotikId,
                            ':host_address'    => $hostAddress,
                            ':comment'         => $comment,
                            ':mikrotik_ref_id' => $refId,
                            ':status'          => $status,
                        ]);
                        $newHostId = $stmt->fetchColumn();

                        $stats['inserted']++;
                        logMessage("[{$mikrotikName}] Host novo inserido: {$hostAddress} ({$refId})");

                        // Registrar evento inicial se status é known
                        if ($status !== 'unknown') {
                            $stmt = $db->prepare('
                                INSERT INTO netwatch_events (netwatch_host_id, status, started_at)
                                VALUES (:host_id, :status, now())
                            ');
                            $stmt->execute([':host_id' => $newHostId, ':status' => $status]);
                        }
                    } catch (\Throwable $e) {
                        logMessage("[{$mikrotikName}] Erro ao inserir host {$refId}: {$e->getMessage()}");
                    }
                }
            }

            // 2. Hosts que existem em ambos → atualizar status
            foreach ($apiByRef as $refId => $apiHost) {
                if (isset($existingByRef[$refId])) {
                    $existing = $existingByRef[$refId];
                    $apiStatus = $apiHost['status'] ?? 'unknown';
                    $newStatus = ($apiStatus === 'up') ? 'up' : (($apiStatus === 'down') ? 'down' : 'unknown');
                    $oldStatus = $existing['current_status'];
                    $statusChanged = ($newStatus !== $oldStatus);

                    // Atualizar host
                    $stmt = $db->prepare('
                        UPDATE netwatch_hosts
                        SET current_status = :status,
                            last_checked_at = now(),
                            active = true
                        WHERE id = :id
                    ');
                    $stmt->execute([
                        ':status' => $newStatus,
                        ':id'     => $existing['id'],
                    ]);

                    // Atualizar status_since apenas se o status mudou
                    if ($statusChanged) {
                        $stmt = $db->prepare('UPDATE netwatch_hosts SET status_since = now() WHERE id = :id');
                        $stmt->execute([':id' => $existing['id']]);
                    }

                    $stats['synced']++;

                    // Registrar evento se status mudou
                    if ($newStatus !== $oldStatus && $newStatus !== 'unknown') {
                        // Fechar evento aberto anterior (se existir)
                        $stmt = $db->prepare('
                            UPDATE netwatch_events
                            SET ended_at = now(),
                                duration_seconds = EXTRACT(EPOCH FROM (now() - started_at))::INTEGER
                            WHERE netwatch_host_id = :host_id
                              AND ended_at IS NULL
                        ');
                        $stmt->execute([':host_id' => $existing['id']]);

                        // Iniciar novo evento
                        $stmt = $db->prepare('
                            INSERT INTO netwatch_events (netwatch_host_id, status, started_at)
                            VALUES (:host_id, :status, now())
                        ');
                        $stmt->execute([
                            ':host_id' => $existing['id'],
                            ':status'  => $newStatus,
                        ]);

                        logMessage("[{$mikrotikName}] Host {$existing['host_address']}: {$oldStatus} → {$newStatus}");
                    }
                }
            }

            // 3. Hosts no banco mas não na API → desativar (soft delete)
            foreach ($existingByRef as $refId => $existing) {
                if (!isset($apiByRef[$refId])) {
                    $stmt = $db->prepare('
                        UPDATE netwatch_hosts
                        SET active = false, current_status = \'unknown\'
                        WHERE id = :id
                    ');
                    $stmt->execute([':id' => $existing['id']]);

                    $stats['deactivated']++;
                    logMessage("[{$mikrotikName}] Host desativado: {$existing['host_address']} ({$refId})");
                }
            }

            // ─── Atualizar status do Mikrotik ─────────────────────────────────

            $stmt = $db->prepare('
                UPDATE mikrotiks
                SET current_status = :status::varchar,
                    status_since = CASE
                        WHEN current_status != :status::varchar THEN now()
                        ELSE status_since
                    END,
                    last_checked_at = now()
                WHERE id = :id
            ');
            $stmt->execute([
                ':status' => $mikrotikNewStatus,
                ':id'     => $mikrotikId,
            ]);

            $stats['processed']++;

        } catch (MikrotikApiException $e) {
            // Mikrotik offline → atualizar status
            $stmt = $db->prepare('
                UPDATE mikrotiks
                SET current_status = \'offline\',
                    status_since = CASE
                        WHEN current_status != \'offline\' THEN now()
                        ELSE status_since
                    END,
                    last_checked_at = now()
                WHERE id = :id
            ');
            $stmt->execute([':id' => $mikrotikId]);

            $stats['errors']++;
            logMessage("[{$mikrotikName}] ERRO: {$e->getMessage()}");

        } catch (\Throwable $e) {
            $stats['errors']++;
            logMessage("[{$mikrotikName}] ERRO INESPERADO: {$e->getMessage()}");
        }
    }

    // ─── Resumo ───────────────────────────────────────────────────────────────

    logMessage("Resumo:");
    logMessage("  Processados: {$stats['processed']}");
    logMessage("  Sincronizados: {$stats['synced']}");
    logMessage("  Inseridos: {$stats['inserted']}");
    logMessage("  Desativados: {$stats['deactivated']}");
    logMessage("  Erros: {$stats['errors']}");

} catch (\Throwable $e) {
    logMessage("ERRO FATAL: " . $e->getMessage());
    exit(1);

} finally {
    // Liberar lock
    if (isset($lockAcquired, $db)) {
        releaseLock($db, $jobName);
        logMessage("Lock liberado.");
    }
}

logMessage("=== Fim da coleta Netwatch ===");
