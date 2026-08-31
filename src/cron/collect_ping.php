<?php

declare(strict_types=1);

/**
 * Mikrotik Watch - Cron de Verificação por Ping (ICMP)
 *
 * Verifica status de equipamentos não-Mikrotik via ICMP ping.
 * Roda a cada 5 minutos (independente dos crons de 1 minuto dos Mikrotiks).
 *
 * Crontab: a cada 5 minutos.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';

date_default_timezone_set($config['app']['timezone']);

$logFile = '/var/log/mikrotik-watch/cron.log';
$jobName = 'ping_check';

function logMessage(string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] {$message}" . PHP_EOL;
    @file_put_contents($GLOBALS['logFile'], $line, FILE_APPEND);
}

// ─── Lock helpers ────────────────────────────────────────────────────────────

function acquireLock(\PDO $db, string $job, int $timeoutMinutes = 15): bool
{
    $stmt = $db->prepare('SELECT 1 FROM cron_locks WHERE job_name = :job AND released_at IS NULL');
    $stmt->execute([':job' => $job]);
    $activeLock = $stmt->fetch();

    if ($activeLock !== false) {
        $stmt = $db->prepare('SELECT 1 FROM cron_locks WHERE job_name = :job AND released_at IS NULL AND locked_at > now() - :timeout * interval \'1 minute\'');
        $stmt->execute([':job' => $job, ':timeout' => $timeoutMinutes]);
        return $stmt->fetch() === false;
    }

    $stmt = $db->prepare('
        INSERT INTO cron_locks (job_name, locked_at)
        VALUES (:job, now())
        ON CONFLICT (job_name) DO UPDATE SET
            locked_at = now(),
            released_at = NULL
    ');
    $stmt->execute([':job' => $job]);

    return true;
}

function releaseLock(\PDO $db, string $job): void
{
    $stmt = $db->prepare('UPDATE cron_locks SET released_at = now() WHERE job_name = :job');
    $stmt->execute([':job' => $job]);
}

// ─── Main ────────────────────────────────────────────────────────────────────

logMessage("=== Início da verificação por Ping ===");

try {
    $db = new PDO(
        "pgsql:host={$config['database']['host']};port={$config['database']['port']};dbname={$config['database']['name']}",
        $config['database']['user'],
        $config['database']['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (\Throwable $e) {
    logMessage("ERRO FATAL: Falha ao conectar no banco: {$e->getMessage()}");
    exit(1);
}

// Adquirir lock
if (!acquireLock($db, $jobName)) {
    logMessage("Outro processo está executando este job. Abortando.");
    exit(0);
}
logMessage("Lock adquirido para '{$jobName}'.");

try {
    // Buscar apenas equipamentos ping
    $stmt = $db->query('
        SELECT id, name, host, current_status
        FROM mikrotiks
        WHERE device_type = \'ping\' AND active = true
        ORDER BY name
    ');
    $devices = $stmt->fetchAll();

    $total = count($devices);
    $success = 0;
    $errors = 0;

    logMessage("Equipamentos ping: {$total}");

    if ($total > 0) {
        // Executar pings sequencialmente usando exec() com timeout.
        // Abordagem simples e confiável — cada ping leva no máximo ~6s
        // (3 pacotes × 2s timeout), então 10 dispositivos = ~60s no pior caso.

        foreach ($devices as $device) {
            $host = $device['host'];

            // Executar ping e capturar saída diretamente
            $output = '';
            $exitCode = 0;
            exec(sprintf('ping -c 3 -W 2 %s 2>&1', escapeshellarg($host)), $output, $exitCode);
            $output = implode("\n", $output);

            // Analisar resultado do ping
            $rtt = null;
            $pingOk = false;

            if ($output !== '') {
                // 1) Tentar extrair RTT via regex (mdev ou sem mdev)
                if (preg_match('/avg\s*=\s*([\d.]+)/', $output, $matches)) {
                    $rtt = (int) round((float) $matches[1]);
                    $pingOk = true;
                }
                // 2) Fallback: verificar se houve pacotes recebidos
                elseif (preg_match('/(\d+)\s+received/', $output, $matches)) {
                    $received = (int) $matches[1];
                    if ($received > 0) {
                        $pingOk = true;
                    }
                }
                // 3) Fallback: verificar se NÃO houve 100% packet loss
                elseif (str_contains($output, 'packet loss')) {
                    $pingOk = !str_contains($output, '100% packet loss');
                }
            }

            // Se ping retornou sucesso mas não conseguimos detectar,
            // usar o exit code como última tentativa
            if (!$pingOk && $exitCode === 0 && $output !== '') {
                $pingOk = true;
            }

            // Atualizar last_rtt_ms
            $stmt = $db->prepare('
                UPDATE mikrotiks
                SET last_rtt_ms = :rtt
                WHERE id = :id
            ');
            $stmt->execute([
                ':rtt' => $rtt,
                ':id'  => $device['id'],
            ]);

            // Avaliar transição de status via StatusTransition (debounce)
            \App\Service\StatusTransition::evaluate(
                db: $db,
                table: 'mikrotiks',
                eventsTable: 'mikrotik_events',
                entityId: $device['id'],
                checkSucceeded: $pingOk,
                onlineValue: 'online',
                offlineValue: 'offline',
                failureThreshold: $config['failure']['threshold'],
            );

            $newStatus = $pingOk ? 'online' : 'offline';
            logMessage("[{$device['name']}] {$newStatus}" . ($rtt !== null ? " (RTT: {$rtt}ms)" : ''));

            $success++;
        }
    }

    // Resumo
    logMessage("───────────────────────────────────────────────");
    logMessage("Resumo:");
    logMessage("  Total: {$total}");
    logMessage("  Processados: {$success}");
    logMessage("  Erros: {$errors}");
    logMessage("───────────────────────────────────────────────");

} catch (\Throwable $e) {
    logMessage("ERRO FATAL: {$e->getMessage()}");
    exit(1);

} finally {
    if (isset($db)) {
        releaseLock($db, $jobName);
        logMessage("Lock liberado.");
    }
}

logMessage("=== Fim da verificação por Ping ===");
