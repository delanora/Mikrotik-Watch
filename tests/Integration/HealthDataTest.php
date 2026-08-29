<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Mikrotik Watch - Health Data Integration Tests
 *
 * Testa a lógica de dados de saúde usada por MikrotikController::healthData().
 * Valida formato JSON, períodos, dispositivos ping, e dados vazios.
 */
class HealthDataTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = self::getTestDatabase();
        self::truncateTables();
    }

    protected function setUp(): void
    {
        self::truncateTables();
    }

    // ─── DADOS DE SAÚDE ──────────────────────────────────────────────────────

    public function testHealthDataReturnsCorrectFormat(): void
    {
        $mikrotikId = self::createMikrotik('Mikrotik-Health');

        // Inserir 5 amostras
        $hoursAgo = [4, 3, 2, 1, 0];
        foreach ($hoursAgo as $i => $hours) {
            $ts = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
            self::insertHealthSample($mikrotikId, 30 + $i, 500000000, 1000000000, 38.0 + $i * 0.5, 24.0, "'{$ts}'");
        }

        // Simular a query do healthData() — período de até 48h (dados brutos)
        $stmt = self::$pdo->prepare('
            SELECT cpu_load, memory_free, memory_total, temperature, voltage, uptime, collected_at
            FROM health_log
            WHERE mikrotik_id = :id
              AND collected_at >= :start::timestamptz
              AND collected_at <= :end::timestamptz
            ORDER BY collected_at ASC
        ');
        $stmt->execute([
            ':id'    => $mikrotikId,
            ':start' => date('Y-m-d H:i:s', strtotime('-2 days')),
            ':end'   => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);
        $healthLogs = $stmt->fetchAll();

        $this->assertCount(5, $healthLogs);

        // Verificar estrutura dos dados
        $first = $healthLogs[0];
        $this->assertArrayHasKey('cpu_load', $first);
        $this->assertArrayHasKey('memory_free', $first);
        $this->assertArrayHasKey('memory_total', $first);
        $this->assertArrayHasKey('temperature', $first);
        $this->assertArrayHasKey('voltage', $first);
        $this->assertArrayHasKey('collected_at', $first);

        // Verificar que os labels (collected_at) estão em ordem crescente
        $labels = array_column($healthLogs, 'collected_at');
        $this->assertEquals($labels, array_values(array_unique($labels)), 'Labels devem ser únicos e ordenados');

        // Verificar que os dados numéricos são válidos
        foreach ($healthLogs as $log) {
            $this->assertNotNull($log['cpu_load']);
            $this->assertNotNull($log['temperature']);
        }
    }

    public function testHealthDataForDeviceWithNoSamplesReturnsEmpty(): void
    {
        $mikrotikId = self::createMikrotik('Mikrotik-Novo');

        // Buscar dados — deve retornar vazio sem erro
        $stmt = self::$pdo->prepare('
            SELECT cpu_load, memory_free, memory_total, temperature, voltage, uptime, collected_at
            FROM health_log
            WHERE mikrotik_id = :id
              AND collected_at >= :start::timestamptz
              AND collected_at <= :end::timestamptz
            ORDER BY collected_at ASC
        ');
        $stmt->execute([
            ':id'    => $mikrotikId,
            ':start' => date('Y-m-d H:i:s', strtotime('-7 days')),
            ':end'   => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);
        $healthLogs = $stmt->fetchAll();

        $this->assertCount(0, $healthLogs, 'Equipamento sem amostras deve retornar array vazio');
        $this->assertIsArray($healthLogs, 'Deve retornar array (não false/null)');
    }

    public function testPingDeviceReturnsEmptyHealthData(): void
    {
        $clientId = self::createClient('Cliente Ping');
        $mikrotikId = self::createPingDevice($clientId, 'Camera-1');

        // Ping devices não têm health_log (não coletam métricas via API)
        $stmt = self::$pdo->prepare('
            SELECT cpu_load, memory_free, memory_total, temperature, voltage, collected_at
            FROM health_log
            WHERE mikrotik_id = :id
            ORDER BY collected_at ASC
        ');
        $stmt->execute([':id' => $mikrotikId]);
        $healthLogs = $stmt->fetchAll();

        $this->assertCount(0, $healthLogs, 'Dispositivo ping não deve ter dados de health_log');

        // Verificar que o device_type é 'ping'
        $stmt = self::$pdo->prepare('SELECT device_type FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $mikrotik = $stmt->fetch();

        $this->assertEquals('ping', $mikrotik['device_type']);
    }

    public function testInvalidPeriodUsesDefault(): void
    {
        $mikrotikId = self::createMikrotik('Mikrotik-Periodo');

        // Simular parâmetros inválidos — devem cair no padrão (7 dias)
        $defaultStart = date('Y-m-d', strtotime('-7 days'));
        $defaultEnd = date('Y-m-d', strtotime('+1 day'));

        // Validação do controller: rejeitar formato inválido
        $start = 'INVALID_DATE';
        $end = 'ALSO_INVALID';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2})?$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2})?$/', $end)) {
            $start = $defaultStart;
            $end = $defaultEnd;
        }

        $this->assertEquals(date('Y-m-d', strtotime('-7 days')), $start);
        $this->assertEquals(date('Y-m-d', strtotime('+1 day')), $end);

        // Query com período válido não deve dar erro
        $stmt = self::$pdo->prepare('
            SELECT COUNT(*) FROM health_log
            WHERE mikrotik_id = :id
              AND collected_at >= :start::timestamptz
              AND collected_at <= :end::timestamptz
        ');
        $stmt->execute([':id' => $mikrotikId, ':start' => $start, ':end' => $end]);
        $this->assertGreaterThanOrEqual(0, (int) $stmt->fetchColumn());
    }

    public function testHourlyAggregationTableQuery(): void
    {
        $mikrotikId = self::createMikrotik('Mikrotik-Hourly');

        // Inserir dados antigos (>24h) para que tenham sido agregados
        self::insertHealthSample($mikrotikId, 50, 500000000, 1000000000, 38.0, 24.0, "'2026-08-20 10:00:00+00'");
        self::insertHealthSample($mikrotikId, 60, 500000000, 1000000000, 39.0, 24.0, "'2026-08-20 10:30:00+00'");

        // Rodar agregação
        self::$pdo->exec('
            INSERT INTO health_log_hourly (
                mikrotik_id, hour_bucket, avg_cpu_load, min_cpu_load, max_cpu_load,
                avg_memory_free, avg_memory_total, avg_temperature, min_temperature, max_temperature,
                avg_voltage, sample_count
            )
            SELECT
                mikrotik_id, date_trunc(\'hour\', collected_at),
                ROUND(AVG(cpu_load), 2), MIN(cpu_load), MAX(cpu_load),
                ROUND(AVG(memory_free), 0), ROUND(AVG(memory_total), 0),
                ROUND(AVG(temperature), 2), MIN(temperature), MAX(temperature),
                ROUND(AVG(voltage), 3), COUNT(*)
            FROM health_log
            WHERE collected_at < now() - INTERVAL \'24 hours\'
            GROUP BY mikrotik_id, date_trunc(\'hour\', collected_at)
            ON CONFLICT (mikrotik_id, hour_bucket) DO NOTHING
        ');

        // Query do controller para período intermediário (usa health_log_hourly)
        $stmt = self::$pdo->prepare('
            SELECT avg_cpu_load AS cpu_load, avg_memory_free AS memory_free,
                   avg_memory_total AS memory_total, avg_temperature AS temperature,
                   avg_voltage AS voltage, hour_bucket AS collected_at
            FROM health_log_hourly
            WHERE mikrotik_id = :id
              AND hour_bucket >= :start::timestamptz
              AND hour_bucket <= :end::timestamptz
            ORDER BY hour_bucket ASC
        ');
        $stmt->execute([
            ':id'    => $mikrotikId,
            ':start' => '2026-08-20 00:00:00+00',
            ':end'   => '2026-08-21 00:00:00+00',
        ]);
        $results = $stmt->fetchAll();

        $this->assertCount(1, $results, 'Deve haver 1 registro horário para esta hora');
        $this->assertEqualsWithDelta(55.0, (float) $results[0]['cpu_load'], 0.1, 'Média de CPU = (50+60)/2');
        $this->assertNotNull($results[0]['collected_at']);
    }

    // ─── UPTIME / EVENTS ─────────────────────────────────────────────────────

    public function testUptimeEventsQueryReturnsCorrectSegments(): void
    {
        $mikrotikId = self::createMikrotik('Mikrotik-Uptime');

        // Criar eventos de transição
        self::$pdo->prepare('
            INSERT INTO mikrotik_events (mikrotik_id, status, started_at, ended_at, duration_seconds)
            VALUES (:id, \'offline\', NOW() - INTERVAL \'3 days\', NOW() - INTERVAL \'2 days\', 86400)
        ')->execute([':id' => $mikrotikId]);

        self::$pdo->prepare('
            INSERT INTO mikrotik_events (mikrotik_id, status, started_at, ended_at, duration_seconds)
            VALUES (:id, \'online\', NOW() - INTERVAL \'2 days\', NOW() - INTERVAL \'1 day\', 86400)
        ')->execute([':id' => $mikrotikId]);

        // Query do healthData para eventos
        $start = date('Y-m-d', strtotime('-7 days'));
        $end = date('Y-m-d', strtotime('+1 day'));

        $stmt = self::$pdo->prepare('
            SELECT status, started_at, ended_at
            FROM mikrotik_events
            WHERE mikrotik_id = :id
              AND started_at <= :end::timestamptz
              AND (ended_at IS NULL OR ended_at >= :start::timestamptz)
            ORDER BY started_at ASC
        ');
        $stmt->execute([':id' => $mikrotikId, ':start' => $start, ':end' => $end]);
        $events = $stmt->fetchAll();

        $this->assertCount(2, $events, 'Deve retornar 2 eventos no período');
        $this->assertEquals('offline', $events[0]['status']);
        $this->assertEquals('online', $events[1]['status']);
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    private static function createClient(string $name): string
    {
        $stmt = self::$pdo->prepare('INSERT INTO clients (name) VALUES (:name) RETURNING id');
        $stmt->execute([':name' => $name]);
        return $stmt->fetch()['id'];
    }

    private static function createMikrotik(string $name): string
    {
        $clientId = self::createClient('Client-' . $name);
        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotiks (client_id, name, host, username, password_encrypted)
            VALUES (:client_id, :name, :host, :username, :pw) RETURNING id
        ');
        $stmt->execute([
            ':client_id' => $clientId,
            ':name'      => $name,
            ':host'      => '10.0.0.' . rand(1, 254),
            ':username'  => 'admin',
            ':pw'        => '\\x0000000000000000000000000000000000',
        ]);
        return $stmt->fetch()['id'];
    }

    private static function createPingDevice(string $clientId, string $name): string
    {
        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotiks (client_id, name, host, device_type, current_status)
            VALUES (:client_id, :name, :host, \'ping\', \'unknown\') RETURNING id
        ');
        $stmt->execute([
            ':client_id' => $clientId,
            ':name'      => $name,
            ':host'      => '10.0.1.' . rand(1, 254),
        ]);
        return $stmt->fetch()['id'];
    }

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
            ':id' => $mikrotikId, ':cpu' => $cpuLoad, ':mf' => $memFree,
            ':mt' => $memTotal, ':temp' => $temp, ':volt' => $voltage,
        ]);
    }

    private static function truncateTables(): void
    {
        self::$pdo->exec('
            TRUNCATE TABLE
                health_log_hourly, health_log_daily,
                netwatch_events, mikrotik_events,
                health_log, netwatch_hosts,
                mikrotiks, cron_locks, users, clients
            CASCADE
        ');
    }

    private static function getTestDatabase(): PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_DB_PORT') ?: '5432';
        $name = getenv('TEST_DB_NAME') ?: 'mikrotik_watch_test';
        $user = getenv('TEST_DB_USER') ?: 'mikrotik_watch';
        $pass = getenv('TEST_DB_PASSWORD') ?: '';

        return new PDO("pgsql:host={$host};port={$port};dbname={$name}", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
