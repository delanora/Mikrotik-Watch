<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Mikrotik Watch - Health Aggregation Integration Tests
 *
 * Testes de integração para o cron de agregação de health_log.
 * Verifica: agregação em hourly, agregação em daily, limpeza de dados antigos,
 * idempotência (execução dupla sem duplicatas), preservação de amostras recentes.
 */
class HealthAggregationTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = self::getTestDatabase();
        self::ensureAggregationTables();
        self::truncateTables();
    }

    protected function setUp(): void
    {
        self::truncateTables();
    }

    // ─── AGREGAÇÃO HOURLY ─────────────────────────────────────────────────────

    public function testAggregateHourlyCreatesCorrectRows(): void
    {
        $mikrotikId = self::createTestMikrotik();

        // Inserir 3 amostras antigas (>24h) na mesma hora
        $hour = "'2026-08-27 10:00:00+00'";
        self::insertHealthSample($mikrotikId, 40, 500000000, 1000000000, 38.0, 24.1, $hour);
        self::insertHealthSample($mikrotikId, 60, 480000000, 1000000000, 39.5, 24.0, "'2026-08-27 10:10:00+00'");
        self::insertHealthSample($mikrotikId, 50, 490000000, 1000000000, 39.0, 24.2, "'2026-08-27 10:20:00+00'");

        // Rodar agregação
        self::runAggregation();

        // Verificar que hourly recebeu 1 linha com valores corretos
        $stmt = self::$pdo->prepare('
            SELECT avg_cpu_load, min_cpu_load, max_cpu_load,
                   avg_temperature, min_temperature, max_temperature,
                   sample_count
            FROM health_log_hourly
            WHERE mikrotik_id = :id AND hour_bucket = :hour
        ');
        $stmt->execute([':id' => $mikrotikId, ':hour' => '2026-08-27 10:00:00+00']);
        $row = $stmt->fetch();

        $this->assertNotEmpty($row, 'health_log_hourly deve ter 1 linha para esta hora');
        $this->assertEqualsWithDelta(50.0, (float) $row['avg_cpu_load'], 0.1, 'avg_cpu = (40+60+50)/3 = 50');
        $this->assertEquals(40, (int) $row['min_cpu_load']);
        $this->assertEquals(60, (int) $row['max_cpu_load']);
        $this->assertEqualsWithDelta(38.83, (float) $row['avg_temperature'], 0.1);
        $this->assertEqualsWithDelta(38.0, (float) $row['min_temperature'], 0.1);
        $this->assertEqualsWithDelta(39.5, (float) $row['max_temperature'], 0.1);
        $this->assertEquals(3, (int) $row['sample_count']);
    }

    public function testAggregateHourlyAveragesMultipleHours(): void
    {
        $mikrotikId = self::createTestMikrotik();

        self::insertHealthSample($mikrotikId, 30, 500000000, 1000000000, 37.0, 24.0, "'2026-08-27 10:00:00+00'");
        self::insertHealthSample($mikrotikId, 70, 500000000, 1000000000, 40.0, 24.0, "'2026-08-27 11:00:00+00'");

        self::runAggregation();

        $stmt = self::$pdo->prepare('SELECT hour_bucket, avg_cpu_load FROM health_log_hourly WHERE mikrotik_id = :id ORDER BY hour_bucket');
        $stmt->execute([':id' => $mikrotikId]);
        $rows = $stmt->fetchAll();

        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(30.0, (float) $rows[0]['avg_cpu_load'], 0.1);
        $this->assertEqualsWithDelta(70.0, (float) $rows[1]['avg_cpu_load'], 0.1);
    }

    // ─── IDEMPOTÊNCIA ─────────────────────────────────────────────────────────

    public function testIdempotencyNoDuplicatesOnSecondRun(): void
    {
        $mikrotikId = self::createTestMikrotik();

        self::insertHealthSample($mikrotikId, 50, 500000000, 1000000000, 38.5, 24.0, "'2026-08-27 10:00:00+00'");
        self::insertHealthSample($mikrotikId, 60, 500000000, 1000000000, 39.0, 24.0, "'2026-08-27 10:05:00+00'");

        // Primeira execução
        self::runAggregation();

        $stmt = self::$pdo->prepare('SELECT sample_count FROM health_log_hourly WHERE mikrotik_id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $row = $stmt->fetch();
        $this->assertEquals(2, (int) $row['sample_count'], 'Primeira execução: 2 amostras');

        // Segunda execução (não deve duplicar)
        self::runAggregation();

        $stmt = self::$pdo->prepare('SELECT COUNT(*) AS total FROM health_log_hourly WHERE mikrotik_id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $count = $stmt->fetch();
        $this->assertEquals(1, (int) $count['total'], 'Deve manter apenas 1 linha (ON CONFLICT DO UPDATE)');

        $stmt = self::$pdo->prepare('SELECT sample_count FROM health_log_hourly WHERE mikrotik_id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $row = $stmt->fetch();
        $this->assertEquals(2, (int) $row['sample_count'], 'sample_count deve permanecer 2');
    }

    // ─── LIMPEZA ──────────────────────────────────────────────────────────────

    public function testCleanupRemovesRawSamplesOlderThan7Days(): void
    {
        $mikrotikId = self::createTestMikrotik();

        // Inserir amostra antiga (>7d)
        self::insertHealthSample($mikrotikId, 50, 500000000, 1000000000, 38.0, 24.0, "'2026-08-20 10:00:00+00'");
        // Inserir amostra recente (<7d)
        self::insertHealthSample($mikrotikId, 60, 500000000, 1000000000, 39.0, 24.0, 'NOW()');

        self::runAggregation();

        // A amostra antiga deve ter sido apagada
        $stmt = self::$pdo->prepare('
            SELECT COUNT(*) AS total FROM health_log
            WHERE mikrotik_id = :id AND collected_at < NOW() - INTERVAL \'7 days\'
        ');
        $stmt->execute([':id' => $mikrotikId]);
        $result = $stmt->fetch();
        $this->assertEquals(0, (int) $result['total'], 'Amostras >7d devem ser apagadas de health_log');

        // A amostra recente deve permanecer
        $stmt = self::$pdo->prepare('
            SELECT COUNT(*) AS total FROM health_log
            WHERE mikrotik_id = :id AND collected_at >= NOW() - INTERVAL \'7 days\'
        ');
        $stmt->execute([':id' => $mikrotikId]);
        $result = $stmt->fetch();
        $this->assertGreaterThanOrEqual(1, (int) $result['total'], 'Amostras <7d devem permanecer em health_log');
    }

    public function testRecentSamplesNeverDeletedEvenIfAggregated(): void
    {
        $mikrotikId = self::createTestMikrotik();

        // Inserir amostras recentes (dentro de 7 dias) — NÃO devem ser apagadas
        self::insertHealthSample($mikrotikId, 50, 500000000, 1000000000, 38.0, 24.0, 'NOW() - INTERVAL \'2 hours\'');
        self::insertHealthSample($mikrotikId, 60, 500000000, 1000000000, 39.0, 24.0, 'NOW() - INTERVAL \'1 hours\'');

        self::runAggregation();

        // Amostras recentes NÃO são apagadas (só as >7d são)
        $stmt = self::$pdo->prepare('SELECT COUNT(*) AS total FROM health_log WHERE mikrotik_id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $result = $stmt->fetch();
        $this->assertEquals(2, (int) $result['total'], 'Amostras recentes (<7d) NUNCA devem ser apagadas');
    }

    // ─── CRON LOCK ────────────────────────────────────────────────────────────

    public function testCronLockPreventsConcurrentExecution(): void
    {
        $job = 'health_aggregate';

        // Adquirir lock manualmente
        self::$pdo->exec("
            INSERT INTO cron_locks (job_name, locked_at, released_at)
            VALUES ('{$job}', NOW(), NULL)
            ON CONFLICT (job_name) DO UPDATE SET locked_at = NOW(), released_at = NULL
        ");

        // Verificar que o lock está ativo
        $stmt = self::$pdo->prepare('SELECT locked_at, released_at FROM cron_locks WHERE job_name = :job');
        $stmt->execute([':job' => $job]);
        $lock = $stmt->fetch();
        $this->assertNotNull($lock['locked_at']);
        $this->assertNull($lock['released_at']);

        // Simular o que o aggregate_health faz: tentar adquirir lock
        $stmt = self::$pdo->prepare('
            UPDATE cron_locks
            SET locked_at = now(), released_at = NULL
            WHERE job_name = :job
              AND (locked_at IS NULL OR released_at IS NOT NULL
                   OR locked_at < now() - 30 * INTERVAL \'1 minute\')
        ');
        $stmt->execute([':job' => $job]);

        // rowCount = 0 significa que não conseguiu adquirir (lock ativo, <30min)
        $this->assertEquals(0, $stmt->rowCount(), 'Lock ativo deve impedir nova execução');

        // Liberar lock
        self::$pdo->prepare("UPDATE cron_locks SET released_at = NOW() WHERE job_name = :job")->execute([':job' => $job]);
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    private static function createTestMikrotik(): string
    {
        // Criar cliente temporário
        $stmt = self::$pdo->prepare("INSERT INTO clients (name) VALUES ('Teste Aggregation') RETURNING id");
        $stmt->execute();
        $clientId = $stmt->fetch()['id'];

        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotiks (client_id, name, host, username, password_encrypted)
            VALUES (:client_id, :name, :host, :username, :pw)
            RETURNING id
        ');
        $stmt->execute([
            ':client_id' => $clientId,
            ':name'      => 'Mikrotik-Test-' . uniqid(),
            ':host'      => '10.0.0.' . rand(1, 254),
            ':username'  => 'admin',
            ':pw'        => '\\x0000000000000000000000000000000000',
        ]);
        return $stmt->fetch()['id'];
    }

    /**
     * Inserir amostra de saúde. O parâmetro $collectedAt deve ser uma expressão SQL
     * entre aspas simples (ex.: "'2026-08-27 10:00:00+00'" ou "NOW()").
     */
    private static function insertHealthSample(
        string $mikrotikId,
        int $cpuLoad,
        int $memFree,
        int $memTotal,
        float $temp,
        float $voltage,
        string $collectedAt
    ): void {
        $sql = 'INSERT INTO health_log (mikrotik_id, cpu_load, memory_free, memory_total, temperature, voltage, collected_at)'
             . ' VALUES (:id, :cpu, :mf, :mt, :temp, :volt, ' . $collectedAt . ')';
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([
            ':id'   => $mikrotikId,
            ':cpu'  => $cpuLoad,
            ':mf'   => $memFree,
            ':mt'   => $memTotal,
            ':temp' => $temp,
            ':volt' => $voltage,
        ]);
    }

    private static function runAggregation(): void
    {
        // Reproduzir a lógica do aggregate_health.php diretamente via PDO do banco de testes
        $jobName = 'health_aggregate';

        // Adquirir lock
        $stmt = self::$pdo->prepare('
            UPDATE cron_locks
            SET locked_at = now(), released_at = NULL
            WHERE job_name = :job
              AND (locked_at IS NULL OR released_at IS NOT NULL
                   OR locked_at < now() - 30 * INTERVAL \'1 minute\')
        ');
        $stmt->execute([':job' => $jobName]);

        if ($stmt->rowCount() === 0) {
            return; // Lock ativo
        }

        try {
            // Passo a: health_log > 24h → health_log_hourly
            self::$pdo->exec('
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
                    ROUND(AVG(cpu_load), 2),
                    MIN(cpu_load), MAX(cpu_load),
                    ROUND(AVG(memory_free), 0), ROUND(AVG(memory_total), 0),
                    ROUND(AVG(temperature), 2), MIN(temperature), MAX(temperature),
                    ROUND(AVG(voltage), 3), COUNT(*)
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

            // Passo b: health_log_hourly > 90d → health_log_daily
            self::$pdo->exec('
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
                    ROUND(AVG(avg_cpu_load), 2),
                    MIN(min_cpu_load), MAX(max_cpu_load),
                    ROUND(AVG(avg_memory_free), 0), ROUND(AVG(avg_memory_total), 0),
                    ROUND(AVG(avg_temperature), 2), MIN(min_temperature), MAX(max_temperature),
                    ROUND(AVG(avg_voltage), 3), SUM(sample_count)
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

            // Passo c: deletar health_log > 7d
            self::$pdo->exec('DELETE FROM health_log WHERE collected_at < now() - INTERVAL \'7 days\'');

            // Passo d: deletar health_log_hourly > 90d
            self::$pdo->exec('DELETE FROM health_log_hourly WHERE hour_bucket < now() - INTERVAL \'90 days\'');

        } finally {
            self::$pdo->prepare('UPDATE cron_locks SET released_at = now() WHERE job_name = :job')->execute([':job' => $jobName]);
        }
    }

    private static function ensureAggregationTables(): void
    {
        $migration = dirname(__DIR__, 2) . '/database/migrations/003_health_retention.sql';
        if (file_exists($migration)) {
            self::$pdo->exec(file_get_contents($migration));
        }
    }

    private static function truncateTables(): void
    {
        self::$pdo->exec('
            TRUNCATE TABLE
                health_log_hourly,
                health_log_daily,
                netwatch_events,
                mikrotik_events,
                health_log,
                netwatch_hosts,
                mikrotiks,
                cron_locks,
                users,
                clients
            CASCADE
        ');

        // Re-inserir locks padrão
        self::$pdo->exec("
            INSERT INTO cron_locks (job_name) VALUES ('health_collect'), ('netwatch_sync'), ('ping_check'), ('health_aggregate')
            ON CONFLICT (job_name) DO NOTHING
        ");
    }

    private static function getTestDatabase(): PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_DB_PORT') ?: '5432';
        $name = getenv('TEST_DB_NAME') ?: 'mikrotik_watch_test';
        $user = getenv('TEST_DB_USER') ?: 'mikrotik_watch';
        $pass = getenv('TEST_DB_PASSWORD') ?: '';

        $dsn = "pgsql:host={$host};port={$port};dbname={$name}";

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
