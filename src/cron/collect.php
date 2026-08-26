<?php

declare(strict_types=1);

/**
 * Mikrotik Watch - Cron de Coleta de Métricas
 *
 * Coleta CPU, memória, temperatura e voltagem de todos os Mikrotiks ativos.
 * Armazena no health_log (histórico) e atualiza campos last_ na tabela mikrotiks.
 *
 * Exemplo de crontab:
 * # * * * * * cd /var/www/Mikrotik-Watch/src && php cron/collect.php >> /var/log/mikrotik-watch/cron.log 2>&1
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';

date_default_timezone_set($config['app']['timezone']);

$logFile = '/var/log/mikrotik-watch/cron.log';
$jobName = 'health_collect';

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
    // Verificar se já existe um lock ativo (released_at IS NULL = ainda rodando)
    $stmt = $db->prepare('SELECT 1 FROM cron_locks WHERE job_name = :job AND released_at IS NULL');
    $stmt->execute([':job' => $job]);
    $activeLock = $stmt->fetch();

    if ($activeLock !== false) {
        // Verificar se expirou (locked_at + timeout > now)
        $stmt = $db->prepare('SELECT 1 FROM cron_locks WHERE job_name = :job AND released_at IS NULL AND locked_at > now() - :timeout * interval \'1 minute\'');
        $stmt->execute([':job' => $job, ':timeout' => $timeoutMinutes]);
        return $stmt->fetch() === false; // Se não expirou, outro processo está rodando
    }

    // Inserir ou atualizar lock
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
    $stmt = $db->prepare('DELETE FROM cron_locks WHERE job_name = :job');
    $stmt->execute([':job' => $job]);
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Converte string de uptime do Mikrotik (ex: "5d3h41m50s") para segundos.
 */
function parseUptime(string $uptime): int
{
    $total = 0;
    if (preg_match('/(\d+)w/', $uptime, $m)) $total += (int)$m[1] * 604800;
    if (preg_match('/(\d+)d/', $uptime, $m)) $total += (int)$m[1] * 86400;
    if (preg_match('/(\d+)h/', $uptime, $m)) $total += (int)$m[1] * 3600;
    if (preg_match('/(\d+)m(?!s)/', $uptime, $m)) $total += (int)$m[1] * 60;
    if (preg_match('/(\d+)s/', $uptime, $m)) $total += (int)$m[1];
    return $total;
}

// ─── Main ────────────────────────────────────────────────────────────────────

logMessage("=== Início da coleta de métricas ===");

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
    // Instanciar crypto para descriptografar senhas
    $crypto = new \App\Service\CredentialCrypto($config['mikrotik']['credential_key']);

    // Listar Mikrotiks ativos
    $stmt = $db->query('
        SELECT id, name, host, port, use_ssl, username, password_encrypted, current_status
        FROM mikrotiks
        WHERE active = true AND device_type = \'mikrotik\'
        ORDER BY name
    ');
    $mikrotiks = $stmt->fetchAll();

    $total = count($mikrotiks);
    $success = 0;
    $errors = 0;

    logMessage("Equipamentos ativos: {$total}");

    foreach ($mikrotiks as $mikrotik) {
        $mikrotikId = $mikrotik['id'];
        $name = $mikrotik['name'];
        $host = $mikrotik['host'];
        $port = (int) $mikrotik['port'];
        $useSsl = (bool) $mikrotik['use_ssl'];
        $username = $mikrotik['username'];

        try {
            // Descriptografar senha (PDO retorna BYTEA como stream)
            $encryptedBytes = $mikrotik['password_encrypted'];
            if (is_resource($encryptedBytes)) {
                $encryptedBytes = stream_get_contents($encryptedBytes);
            }
            $encryptedBase64 = base64_encode($encryptedBytes);
            $password = $crypto->decrypt($encryptedBase64);

            // Criar cliente
            $client = new \App\Service\MikrotikClient(
                host: $host,
                username: $username,
                password: $password,
                port: $port,
                useSsl: $useSsl,
                verifySsl: !$config['mikrotik']['allow_self_signed'],
                timeout: $config['mikrotik']['default_timeout'],
            );

            // ─── Coletar system/resource (CPU, memória, uptime, versão) ──────
            $resource = $client->systemResource();

            $cpuLoad = (int) ($resource['cpu-load'] ?? 0);
            $memoryFree = (int) ($resource['free-memory'] ?? 0);
            $memoryTotal = (int) ($resource['total-memory'] ?? 0);
            $uptimeStr = $resource['uptime'] ?? '0s';
            $uptimeSeconds = parseUptime($uptimeStr);
            $boardName = $resource['board-name'] ?? null;
            $routerosVersion = $resource['version'] ?? null;

            // ─── Coletar system/health (temperatura, voltagem) ────────────────
            $temperature = null;
            $voltage = null;

            try {
                $health = $client->systemHealth();
                // systemHealth retorna array de [{name, type, value}, ...]
                foreach ($health as $item) {
                    $itemName = $item['name'] ?? '';
                    $itemValue = $item['value'] ?? '';
                    if ($itemName === 'cpu-temperature' && is_numeric($itemValue)) {
                        $temperature = (float) $itemValue;
                    } elseif ($itemName === 'temperature' && is_numeric($itemValue)) {
                        $temperature = (float) $itemValue;
                    } elseif ($itemName === 'voltage' && is_numeric($itemValue)) {
                        $voltage = (float) $itemValue;
                    }
                }
            } catch (\Throwable $e) {
                // Alguns modelos não reportam health — ignora silenciosamente
            }

            // ─── Atualizar mikrotiks (campos last_*) ─────────────────────────
            $stmt = $db->prepare('
                UPDATE mikrotiks
                SET current_status = \'online\'::varchar,
                    status_since = CASE
                        WHEN current_status != \'online\' THEN now()
                        ELSE status_since
                    END,
                    last_checked_at = now(),
                    last_cpu_load = :cpu_load,
                    last_memory_free = :memory_free,
                    last_memory_total = :memory_total,
                    last_temperature = :temperature,
                    last_voltage = :voltage,
                    board_name = :board_name,
                    routeros_version = :routeros_version
                WHERE id = :id
            ');
            $stmt->execute([
                ':cpu_load'         => $cpuLoad,
                ':memory_free'      => $memoryFree,
                ':memory_total'     => $memoryTotal,
                ':temperature'      => $temperature,
                ':voltage'          => $voltage,
                ':board_name'       => $boardName,
                ':routeros_version' => $routerosVersion,
                ':id'               => $mikrotikId,
            ]);

            // ─── Inserir no health_log (histórico) ───────────────────────────
            $stmt = $db->prepare('
                INSERT INTO health_log (mikrotik_id, cpu_load, memory_free, memory_total, temperature, voltage, uptime)
                VALUES (:mikrotik_id, :cpu_load, :memory_free, :memory_total, :temperature, :voltage, :uptime)
            ');
            $stmt->execute([
                ':mikrotik_id'  => $mikrotikId,
                ':cpu_load'     => $cpuLoad,
                ':memory_free'  => $memoryFree,
                ':memory_total' => $memoryTotal,
                ':temperature'  => $temperature,
                ':voltage'      => $voltage,
                ':uptime'       => $uptimeSeconds,
            ]);

            $memPct = $memoryTotal > 0 ? round(($memoryTotal - $memoryFree) / $memoryTotal * 100, 1) : 0;
            $tempStr = $temperature !== null ? "{$temperature}°C" : 'N/A';
            $voltStr = $voltage !== null ? "{$voltage}V" : 'N/A';

            logMessage("[{$name}] ✅ CPU: {$cpuLoad}% | RAM: {$memPct}% ({$memoryFree}/{$memoryTotal}) | Temp: {$tempStr} | Volt: {$voltStr} | Uptime: {$uptimeStr}");
            $success++;

        } catch (\App\Exception\MikrotikApiException $e) {
            // Conexão falhou → marcar offline
            $stmt = $db->prepare('
                UPDATE mikrotiks
                SET current_status = \'offline\'::varchar,
                    status_since = CASE
                        WHEN current_status != \'offline\' THEN now()
                        ELSE status_since
                    END,
                    last_checked_at = now()
                WHERE id = :id
            ');
            $stmt->execute([':id' => $mikrotikId]);

            logMessage("[{$name}] ❌ OFFLINE: {$e->getMessage()}");
            $errors++;

        } catch (\Throwable $e) {
            $errors++;
            logMessage("[{$name}] ❌ ERRO: {$e->getMessage()}");
        }
    }

    // ─── Resumo ───────────────────────────────────────────────────────────────

    logMessage("───────────────────────────────────────────────");
    logMessage("Resumo:");
    logMessage("  Total: {$total}");
    logMessage("  Sucesso: {$success}");
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

logMessage("=== Fim da coleta de métricas ===");
