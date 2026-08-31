<?php

declare(strict_types=1);

/**
 * Mikrotik Watch - Cron de Coleta e Sincronização do Netwatch (Paralelo)
 *
 * Usa curl_multi para disparar requisições netwatch em paralelo.
 *
 * Crontab: * * * * * cd /var/www/Mikrotik-Watch/src && php cron/collect_netwatch.php >> /var/log/mikrotik-watch/cron.log 2>&1
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';

use App\Service\CredentialCrypto;
use App\Exception\MikrotikApiException;

date_default_timezone_set($config['app']['timezone']);

$logFile = '/var/log/mikrotik-watch/cron.log';
$jobName = 'netwatch_sync';
$startTime = microtime(true);

function logMessage(string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] {$message}" . PHP_EOL;
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

/**
 * Sincroniza os hosts netwatch de um Mikrotik com o banco de dados.
 */
function syncNetwatchHosts(
    PDO $db,
    int|string $mikrotikId,
    string $mikrotikName,
    array $apiHosts,
    array $existingHosts,
    array &$stats
): void {
    // Indexar existentes por mikrotik_ref_id
    $existingByRef = [];
    foreach ($existingHosts as $existing) {
        if ($existing['mikrotik_ref_id'] !== null) {
            $existingByRef[(string) $existing['mikrotik_ref_id']] = $existing;
        }
    }

    // Indexar API hosts por .id (normalizando para string)
    $apiByRef = [];
    $skippedNoRef = 0;
    foreach ($apiHosts as $apiHost) {
        $refId = $apiHost['.id'] ?? null;
        if ($refId !== null) {
            $apiByRef[(string) $refId] = $apiHost;
        } else {
            $skippedNoRef++;
            logMessage("[{$mikrotikName}] ⚠️ Host sem .id: " . json_encode(array_keys($apiHost)));
        }
    }

    if ($skippedNoRef > 0) {
        logMessage("[{$mikrotikName}] ⚠️ {$skippedNoRef} hosts ignorados (sem campo .id na resposta da API)");
    }
    logMessage("[{$mikrotikName}] Sincronizando: " . count($apiByRef) . " hosts na API, " . count($existingByRef) . " existentes no banco");

    // Log das chaves de referência para diagnóstico
    if (count($apiByRef) > 0) {
        logMessage("[{$mikrotikName}] API refs: " . implode(', ', array_keys($apiByRef)));
    }
    if (count($existingByRef) > 0) {
        logMessage("[{$mikrotikName}] DB refs: " . implode(', ', array_keys($existingByRef)));
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
                    ':mikrotik_ref_id' => (string) $refId,
                    ':status'          => $status,
                ]);
                $newHostId = $stmt->fetchColumn();

                $stats['inserted']++;
                logMessage("[{$mikrotikName}] Host novo inserido: {$hostAddress} ({$refId})");

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

    // 2. Hosts que existem em ambos → atualizar status via StatusTransition (debounce)
    foreach ($apiByRef as $refId => $apiHost) {
        if (isset($existingByRef[$refId])) {
            $existing = $existingByRef[$refId];
            $apiStatus = $apiHost['status'] ?? 'unknown';
            $newApiStatus = ($apiStatus === 'up') ? 'up' : (($apiStatus === 'down') ? 'down' : 'unknown');

            // Marcar ativo
            $stmt = $db->prepare('UPDATE netwatch_hosts SET active = true WHERE id = :id');
            $stmt->execute([':id' => $existing['id']]);

            // Avaliar transição via StatusTransition (debounce)
            $checkSucceeded = ($newApiStatus === 'up');
            \App\Service\StatusTransition::evaluate(
                db: $db,
                table: 'netwatch_hosts',
                eventsTable: 'netwatch_events',
                entityId: (int) $existing['id'],
                checkSucceeded: $checkSucceeded,
                onlineValue: 'up',
                offlineValue: 'down',
                failureThreshold: $config['failure']['threshold'],
            );

            $stats['synced']++;

            if ($newApiStatus !== $existing['current_status'] && $newApiStatus !== 'unknown') {
                logMessage("[{$mikrotikName}] Host {$existing['host_address']}: {$existing['current_status']} → {$newApiStatus}");
            }
        }
    }

    // 3. Hosts no banco mas não na API → desativar
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
        WHERE active = true AND device_type = \'mikrotik\'
        ORDER BY name
    ');
    $mikrotiks = $stmt->fetchAll();

    if (empty($mikrotiks)) {
        logMessage("Nenhum equipamento Mikrotik ativo encontrado.");
        releaseLock($db, $jobName);
        exit(0);
    }

    logMessage("Equipamentos ativos: " . count($mikrotiks));

    // ─── Preparar requisições netwatch em paralelo ────────────────────────────

    $batchRequests = [];
    $mikrotikIndex = [];

    foreach ($mikrotiks as $mikrotik) {
        $id = $mikrotik['id'];

        // Descriptografar senha (PDO retorna BYTEA como stream)
        $pwRaw = $mikrotik['password_encrypted'];
        if (is_resource($pwRaw)) {
            $pwRaw = stream_get_contents($pwRaw);
        }
        $encryptedBase64 = base64_encode($pwRaw);
        $password = $crypto->decrypt($encryptedBase64);

        $mikrotikIndex[$id] = $mikrotik;
        $mikrotikIndex[$id]['_password'] = $password;

        $batchRequests[] = [
            'mikrotik_id' => $id,
            'key'         => $id,
            'endpoint'    => '/rest/tool/netwatch',
            'host'        => $mikrotik['host'],
            'port'        => (int) $mikrotik['port'],
            'use_ssl'     => (bool) $mikrotik['use_ssl'],
            'username'    => $mikrotik['username'],
            'password'    => $password,
        ];
    }

    // ─── Disparar TODAS as requisições em paralelo ───────────────────────────
    // Nota: maxConcurrency reduzido de 30 para 10 para evitar sobrecarga
    // na API REST do Mikrotik (especialmente em dispositivos pequenos).
    // O retry automático do batchRequest já ajuda a recuperar falhas.

    $results = \App\Service\MikrotikClient::batchGet(
        $batchRequests,
        timeout: $config['mikrotik']['default_timeout'],
        verifySsl: !$config['mikrotik']['allow_self_signed'],
        caCertPath: null,
        maxConcurrency: 10,
    );

    logMessage("Requisições HTTP disparadas e coletadas em paralelo.");

    // ─── Processar resultados individualmente ────────────────────────────────

    $stats = [
        'processed'   => 0,
        'synced'      => 0,
        'inserted'    => 0,
        'deactivated' => 0,
        'errors'      => 0,
    ];

    foreach ($mikrotiks as $mikrotik) {
        $mikrotikId = $mikrotik['id'];
        $mikrotikName = $mikrotik['name'];

        $result = $results[$mikrotikId] ?? null;

        try {
            // Verificar erro de conexão
            if ($result === null || isset($result['error'])) {
                $errorMsg = $result['error'] ?? 'Sem resposta';
                throw new MikrotikApiException(
                    "Falha de conexão com {$mikrotik['host']}: {$errorMsg}",
                    $mikrotik['host'],
                    '/rest/tool/netwatch',
                    0
                );
            }

            $apiHosts = $result['data'];
            $mikrotikNewStatus = 'online';

            // Debug: log do tipo e tamanho da resposta para diagnóstico
            $hostCount = is_array($apiHosts) ? count($apiHosts) : 0;
            logMessage("[{$mikrotikName}] Conectado. Hosts na API: {$hostCount} (tipo: " . gettype($apiHosts) . ")");

            // Log da estrutura bruta da resposta (primeiros 500 chars)
            $rawJson = json_encode($apiHosts, JSON_UNESCAPED_SLASHES);
            logMessage("[{$mikrotikName}] Resposta bruta (preview): " . substr($rawJson, 0, 500));

            // Se a resposta for um objeto com chave 'data', extrair os hosts
            if (isset($apiHosts['data']) && is_array($apiHosts['data'])) {
                logMessage("[{$mikrotikName}] ⚠️ Resposta é objeto com chave 'data'. Extraindo hosts...");
                $apiHosts = $apiHosts['data'];
                $hostCount = count($apiHosts);
            }

            if ($hostCount > 0 && is_array($apiHosts)) {
                $sample = reset($apiHosts);
                logMessage("[{$mikrotikName}] Amostra do primeiro host: " . json_encode($sample, JSON_UNESCAPED_SLASHES));
            }

            // ─── Sincronizar hosts ────────────────────────────────────────────

            $stmt = $db->prepare('
                SELECT id, host_address, mikrotik_ref_id, current_status, status_since
                FROM netwatch_hosts
                WHERE mikrotik_id = :mikrotik_id
            ');
            $stmt->execute([':mikrotik_id' => $mikrotikId]);
            $existingHosts = $stmt->fetchAll();

            syncNetwatchHosts($db, $mikrotikId, $mikrotikName, $apiHosts, $existingHosts, $stats);

            $stats['processed']++;

        } catch (MikrotikApiException $e) {
            // Falha na API netwatch NÃO significa que o Mikrotik está offline.
            // O status online/offline é gerenciado pelo collect.php (health).
            // Retry individual: se o batch falhou para este device, tentar uma
            // vez mais diretamente via MikrotikClient (sequencial, sem curl_multi).
            $retryOk = false;
            if (str_contains($e->getMessage(), 'cURL error') || str_contains($e->getMessage(), 'Timeout')) {
                logMessage("[{$mikrotikName}] ⚠️ Retry individual para netwatch...");
                try {
                    usleep(500_000); // 500ms
                    $retryClient = new \App\Service\MikrotikClient(
                        host: $mikrotik['host'],
                        username: $mikrotik['username'],
                        password: $mikrotikIndex[$mikrotikId]['_password'] ?? '',
                        port: (int) $mikrotik['port'],
                        useSsl: (bool) $mikrotik['use_ssl'],
                        verifySsl: !$config['mikrotik']['allow_self_signed'],
                        timeout: $config['mikrotik']['default_timeout'] + 3,
                    );
                    $apiHosts = $retryClient->netwatch();
                    $retryOk = true;

                    logMessage("[{$mikrotikName}] ✅ Retry bem-sucedido. Hosts: " . count($apiHosts));

                    // Sincronizar no retry
                    $stmt = $db->prepare('
                        SELECT id, host_address, mikrotik_ref_id, current_status, status_since
                        FROM netwatch_hosts
                        WHERE mikrotik_id = :mikrotik_id
                    ');
                    $stmt->execute([':mikrotik_id' => $mikrotikId]);
                    $existingHosts = $stmt->fetchAll();

                    syncNetwatchHosts($db, $mikrotikId, $mikrotikName, $apiHosts, $existingHosts, $stats);
                    $stats['processed']++;

                } catch (\Throwable $retryErr) {
                    logMessage("[{$mikrotikName}] ❌ Retry também falhou: {$retryErr->getMessage()}");
                }
            }

            if (!$retryOk) {
                $stats['errors']++;
                logMessage("[{$mikrotikName}] ERRO netwatch: {$e->getMessage()}");
            }

        } catch (\Throwable $e) {
            $stats['errors']++;
            logMessage("[{$mikrotikName}] ERRO INESPERADO: {$e->getMessage()}");
        }
    }

    // ─── Resumo ───────────────────────────────────────────────────────────────
    $elapsed = round(microtime(true) - $startTime, 2);

    logMessage("───────────────────────────────────────────────");
    logMessage("Resumo:");
    logMessage("  Processados: {$stats['processed']}");
    logMessage("  Sincronizados: {$stats['synced']}");
    logMessage("  Inseridos: {$stats['inserted']}");
    logMessage("  Desativados: {$stats['deactivated']}");
    logMessage("  Erros: {$stats['errors']}");
    logMessage("  Tempo total: {$elapsed}s");
    logMessage("───────────────────────────────────────────────");

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
