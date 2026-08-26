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
    echo $line;
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

// ─── ICMP Ping ──────────────────────────────────────────────────────────────

/**
 * Executa ping ICMP em um host e retorna o RTT médio em ms (ou null se falhar).
 */
function icmpPing(string $host, int $count = 3, int $timeout = 2): ?int
{
    $cmd = sprintf('ping -c %d -W %d %s 2>&1', $count, $timeout, escapeshellarg($host));

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
        return null;
    }

    // Extrair RTT médio do output do ping
    // Formato típico: rtt min/avg/max/mdev = 1.234/5.678/9.012/3.456 ms
    foreach ($output as $line) {
        if (str_contains($line, 'avg')) {
            if (preg_match('/avg\s*=\s*[\d.]+\/([\d.]+)\//', $line, $matches)) {
                return (int) round((float) $matches[1]);
            }
        }
    }

    return null;
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

    // Executar pings em paralelo (máximo 10 simultâneos)
    $processes = [];
    $maxParallel = 10;

    foreach ($devices as $device) {
        // Aguardar slot disponível
        while (count($processes) >= $maxParallel) {
            foreach ($processes as $key => $proc) {
                if (proc_get_status($proc['handle'])['进程pid'] === false || 
                    proc_get_status($proc['handle'])['running'] === false) {
                    $status = proc_get_status($proc['handle']);
                    proc_close($proc['handle']);
                    unset($processes[$key]);
                }
            }
            usleep(100000); // 100ms
        }

        // Iniciar ping em background
        $host = $device['host'];
        $cmd = sprintf('ping -c 3 -W 2 %s 2>&1', escapeshellarg($host));
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $handle = proc_open($cmd, $descriptors, $pipes);

        if (is_resource($handle)) {
            $processes[] = [
                'handle' => $handle,
                'pipes'  => $pipes,
                'device' => $device,
            ];
        }
    }

    // Aguardar e processar todos os processos
    foreach ($processes as $proc) {
        $output = stream_get_contents($proc['pipes'][1]);
        fclose($proc['pipes'][0]);
        fclose($proc['pipes'][1]);
        fclose($proc['pipes'][2]);
        proc_close($proc['handle']);

        $device = $proc['device'];
        $host = $device['host'];
        $oldStatus = $device['current_status'];

        // Analisar resultado do ping
        $rtt = null;
        $pingOk = false;

        // Formato típico: rtt min/avg/max/mdev = 1.234/5.678/9.012/3.456 ms
        if (preg_match('/min\/avg\/max\/mdev\s*=\s*[\d.]+\/([\d.]+)\//', $output, $matches)) {
            $rtt = (int) round((float) $matches[1]);
            $pingOk = true;
        } elseif (str_contains($output, 'received') && !str_contains($output, '0 received')) {
            $pingOk = true;
        }

        $newStatus = $pingOk ? 'online' : 'offline';

        // Atualizar mikrotiks
        $statusChanged = ($newStatus !== $oldStatus);

        $stmt = $db->prepare('
            UPDATE mikrotiks
            SET current_status = :status,
                last_checked_at = now(),
                last_rtt_ms = :rtt
            WHERE id = :id
        ');
        $stmt->execute([
            ':status' => $newStatus,
            ':rtt'    => $rtt,
            ':id'     => $device['id'],
        ]);

        // Atualizar status_since se mudou
        if ($statusChanged) {
            $stmt = $db->prepare('UPDATE mikrotiks SET status_since = now() WHERE id = :id');
            $stmt->execute([':id' => $device['id']]);

            // Registrar evento em mikrotik_events
            $stmt = $db->prepare('
                INSERT INTO mikrotik_events (mikrotik_id, status, started_at)
                VALUES (:mikrotik_id, :status, now())
            ');
            $stmt->execute([':mikrotik_id' => $device['id'], ':status' => $newStatus]);

            logMessage("[{$device['name']}] {$oldStatus} → {$newStatus}" . ($rtt !== null ? " (RTT: {$rtt}ms)" : ''));
        } else {
            logMessage("[{$device['name']}] {$newStatus}" . ($rtt !== null ? " (RTT: {$rtt}ms)" : ''));
        }

        $success++;
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
