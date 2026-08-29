<?php
/**
 * Mikrotik Watch - Agregação e Retenção de health_log
 *
 * Roda 1x por dia (recomendado: 03:00). Usa lock via cron_locks.
 *
 * Crontab sugerida:
 * 0 3 * * * cd /var/www/Mikrotik-Watch/src && php cron/aggregate_health.php >> /var/log/mikrotik-watch/cron.log 2>&1
 *
 * Lógica:
 *   a) Agregar health_log > 24h → health_log_hourly (AVG/MIN/MAX por hora)
 *   b) Agregar health_log_hourly > 90d → health_log_daily (AVG/MIN/MAX por dia)
 *   c) Apagar health_log > 7d (já agregado em hourly)
 *   d) Apagar health_log_hourly > 90d (já agregado em daily)
 *   e) health_log_daily: retenção indefinida
 */

declare(strict_types=1);

$startTime = microtime(true);

// ─── Conexão com o banco ────────────────────────────────────────────────────
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';

$dbCfg = $config['database'];
$dsn = "pgsql:host={$dbCfg['host']};port={$dbCfg['port']};dbname={$dbCfg['name']}";
$db = new PDO($dsn, $dbCfg['user'], $dbCfg['password'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

// ─── Lock ────────────────────────────────────────────────────────────────────
$jobName = 'health_aggregate';
$timeoutMinutes = 30; // Timeout maior porque pode processar muitos registros

$stmt = $db->prepare('
    UPDATE cron_locks
    SET locked_at = now(), released_at = NULL
    WHERE job_name = :job
      AND (locked_at IS NULL OR released_at IS NOT NULL
           OR locked_at < now() - :timeout * INTERVAL \'1 minute\')
');
$stmt->execute([':job' => $jobName, ':timeout' => $timeoutMinutes]);

if ($stmt->rowCount() === 0) {
    echo date('[Y-m-d H:i:s]') . " [aggregate_health] Lock já ativo, abortando ciclo.\n";
    exit(0);
}

function releaseLock(PDO $db, string $job): void
{
    $stmt = $db->prepare('UPDATE cron_locks SET released_at = now() WHERE job_name = :job');
    $stmt->execute([':job' => $job]);
}

try {
    // ─── Passo a) Agregar health_log > 24h → health_log_hourly ────────────
    echo date('[Y-m-d H:i:s]') . " [aggregate_health] Passo a: Agregando health_log > 24h em health_log_hourly...\n";

    try {
        $db->exec('
            INSERT INTO health_log_hourly (
                mikrotik_id, hour_bucket,
                avg_cpu_load, min_cpu_load, max_cpu_load,
                avg_memory_free, avg_memory_total,
                avg_temperature, min_temperature, max_temperature,
                avg_voltage, sample_count
            )
            SELECT
                mikrotik_id,
                date_trunc(\'hour\', collected_at) AS hour_bucket,
                ROUND(AVG(cpu_load), 2) AS avg_cpu_load,
                MIN(cpu_load) AS min_cpu_load,
                MAX(cpu_load) AS max_cpu_load,
                ROUND(AVG(memory_free), 0) AS avg_memory_free,
                ROUND(AVG(memory_total), 0) AS avg_memory_total,
                ROUND(AVG(temperature), 2) AS avg_temperature,
                MIN(temperature) AS min_temperature,
                MAX(temperature) AS max_temperature,
                ROUND(AVG(voltage), 3) AS avg_voltage,
                COUNT(*) AS sample_count
            FROM health_log
            WHERE collected_at < now() - INTERVAL \'24 hours\'
            GROUP BY mikrotik_id, date_trunc(\'hour\', collected_at)
            ON CONFLICT (mikrotik_id, hour_bucket) DO UPDATE SET
                avg_cpu_load = EXCLUDED.avg_cpu_load,
                min_cpu_load = EXCLUDED.min_cpu_load,
                max_cpu_load = EXCLUDED.max_cpu_load,
                avg_memory_free = EXCLUDED.avg_memory_free,
                avg_memory_total = EXCLUDED.avg_memory_total,
                avg_temperature = EXCLUDED.avg_temperature,
                min_temperature = EXCLUDED.min_temperature,
                max_temperature = EXCLUDED.max_temperature,
                avg_voltage = EXCLUDED.avg_voltage,
                sample_count = EXCLUDED.sample_count
        ');
        echo date('[Y-m-d H:i:s]') . " [aggregate_health] Passo a: OK.\n";
    } catch (PDOException $e) {
        echo date('[Y-m-d H:i:s]') . " [aggregate_health] ERRO no passo a: " . $e->getMessage() . "\n";
    }

    // ─── Passo b) Agregar health_log_hourly > 90d → health_log_daily ──────
    echo date('[Y-m-d H:i:s]') . " [aggregate_health] Passo b: Agregando health_log_hourly > 90d em health_log_daily...\n";

    try {
        $db->exec('
            INSERT INTO health_log_daily (
                mikrotik_id, day_bucket,
                avg_cpu_load, min_cpu_load, max_cpu_load,
                avg_memory_free, avg_memory_total,
                avg_temperature, min_temperature, max_temperature,
                avg_voltage, sample_count
            )
            SELECT
                mikrotik_id,
                hour_bucket::date AS day_bucket,
                ROUND(AVG(avg_cpu_load), 2) AS avg_cpu_load,
                MIN(min_cpu_load) AS min_cpu_load,
                MAX(max_cpu_load) AS max_cpu_load,
                ROUND(AVG(avg_memory_free), 0) AS avg_memory_free,
                ROUND(AVG(avg_memory_total), 0) AS avg_memory_total,
                ROUND(AVG(avg_temperature), 2) AS avg_temperature,
                MIN(min_temperature) AS min_temperature,
                MAX(max_temperature) AS max_temperature,
                ROUND(AVG(avg_voltage), 3) AS avg_voltage,
                SUM(sample_count) AS sample_count
            FROM health_log_hourly
            WHERE hour_bucket < now() - INTERVAL \'90 days\'
            GROUP BY mikrotik_id, hour_bucket::date
            ON CONFLICT (mikrotik_id, day_bucket) DO UPDATE SET
                avg_cpu_load = EXCLUDED.avg_cpu_load,
                min_cpu_load = EXCLUDED.min_cpu_load,
                max_cpu_load = EXCLUDED.max_cpu_load,
                avg_memory_free = EXCLUDED.avg_memory_free,
                avg_memory_total = EXCLUDED.avg_memory_total,
                avg_temperature = EXCLUDED.avg_temperature,
                min_temperature = EXCLUDED.min_temperature,
                max_temperature = EXCLUDED.max_temperature,
                avg_voltage = EXCLUDED.avg_voltage,
                sample_count = health_log_daily.sample_count + EXCLUDED.sample_count
        ');
        echo date('[Y-m-d H:i:s]') . " [aggregate_health] Passo b: OK.\n";
    } catch (PDOException $e) {
        echo date('[Y-m-d H:i:s]') . " [aggregate_health] ERRO no passo b: " . $e->getMessage() . "\n";
    }

    // ─── Passo c) Apagar health_log > 7d ──────────────────────────────────
    echo date('[Y-m-d H:i:s]') . " [aggregate_health] Passo c: Removendo health_log > 7d...\n";

    try {
        $stmt = $db->exec('DELETE FROM health_log WHERE collected_at < now() - INTERVAL \'7 days\'');
        echo date('[Y-m-d H:i:s]') . " [aggregate_health] Passo c: OK (" . $stmt . " linhas removidas).\n";
    } catch (PDOException $e) {
        echo date('[Y-m-d H:i:s]') . " [aggregate_health] ERRO no passo c: " . $e->getMessage() . "\n";
    }

    // ─── Passo d) Apagar health_log_hourly > 90d ──────────────────────────
    echo date('[Y-m-d H:i:s]') . " [aggregate_health] Passo d: Removendo health_log_hourly > 90d...\n";

    try {
        $stmt = $db->exec('DELETE FROM health_log_hourly WHERE hour_bucket < now() - INTERVAL \'90 days\'');
        echo date('[Y-m-d H:i:s]') . " [aggregate_health] Passo d: OK (" . $stmt . " linhas removidas).\n";
    } catch (PDOException $e) {
        echo date('[Y-m-d H:i:s]') . " [aggregate_health] ERRO no passo d: " . $e->getMessage() . "\n";
    }

    // ─── Passo e) health_log_daily: sem expiração ──────────────────────────
    echo date('[Y-m-d H:i:s]') . " [aggregate_health] Passo e: health_log_daily sem expiração (retenção indefinida).\n";

} finally {
    releaseLock($db, $jobName);
}

$elapsed = round(microtime(true) - $startTime, 2);
echo date('[Y-m-d H:i:s]') . " [aggregate_health] Ciclo finalizado em {$elapsed}s.\n";
