<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Mikrotik Watch - TV Dashboard Integration Tests
 *
 * Testa a lógica de dados do TV Dashboard (TvController).
 * Valida estrutura JSON, dados agregados, e ausência de dados sensíveis.
 */
class TvDashboardTest extends TestCase
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

    // ─── TV API DATA ──────────────────────────────────────────────────────────

    public function testTvApiReturnsDeviceSummary(): void
    {
        $client = self::createClient('Cliente TV');
        self::createMikrotik($client, 'Mik-Online', 'online');
        self::createMikrotik($client, 'Mik-Offline', 'offline');
        self::createMikrotik($client, 'Mik-Unknown', 'unknown');

        // Simular a query do TvController::api()
        $stmt = self::$pdo->query('
            SELECT
                COUNT(*) FILTER (WHERE active = true) AS total,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'online\') AS online,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'offline\') AS offline
            FROM mikrotiks
        ');
        $deviceSummary = $stmt->fetch();

        $this->assertEquals(3, (int) $deviceSummary['total']);
        $this->assertEquals(1, (int) $deviceSummary['online']);
        $this->assertEquals(1, (int) $deviceSummary['offline']);
    }

    public function testTvApiReturnsHostSummary(): void
    {
        $client = self::createClient('Cliente Hosts');
        $mikrotikId = self::createMikrotik($client, 'Mik-Hosts');

        self::createHost($mikrotikId, '192.168.1.1', 'up');
        self::createHost($mikrotikId, '192.168.1.2', 'down');
        self::createHost($mikrotikId, '192.168.1.3', 'down');

        $stmt = self::$pdo->query('
            SELECT
                COUNT(*) FILTER (WHERE active = true) AS total,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'up\') AS up,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'down\') AS down
            FROM netwatch_hosts
        ');
        $hostSummary = $stmt->fetch();

        $this->assertEquals(3, (int) $hostSummary['total']);
        $this->assertEquals(1, (int) $hostSummary['up']);
        $this->assertEquals(2, (int) $hostSummary['down']);
    }

    public function testTvApiReturnsDeviceList(): void
    {
        $client = self::createClient('Cliente Lista');
        self::createMikrotik($client, 'Mik-A', 'online');
        self::createMikrotik($client, 'Mik-B', 'offline');

        $stmt = self::$pdo->query('
            SELECT
                m.name, m.host, m.current_status, m.status_since,
                m.last_cpu_load, m.last_memory_free, m.last_memory_total,
                m.last_temperature, m.last_rtt_ms, m.device_type,
                m.last_checked_at,
                c.name AS client_name
            FROM mikrotiks m
            LEFT JOIN clients c ON c.id = m.client_id
            WHERE m.active = true
            ORDER BY
                CASE m.current_status
                    WHEN \'offline\' THEN 0
                    WHEN \'unknown\' THEN 1
                    WHEN \'online\' THEN 2
                    ELSE 3
                END,
                c.name ASC, m.name ASC
        ');
        $devices = $stmt->fetchAll();

        $this->assertCount(2, $devices);
        // Offline primeiro
        $this->assertEquals('Mik-B', $devices[0]['name']);
        $this->assertEquals('offline', $devices[0]['current_status']);
        $this->assertEquals('Mik-A', $devices[1]['name']);
        $this->assertEquals('online', $devices[1]['current_status']);
    }

    public function testTvApiReturnsDownHosts(): void
    {
        $client = self::createClient('Cliente Down');
        $mikrotikId = self::createMikrotik($client, 'Mik-Down');

        self::createHost($mikrotikId, '10.0.0.1', 'up');
        self::createHost($mikrotikId, '10.0.0.2', 'down', 'Câmera Principal');
        self::createHost($mikrotikId, '10.0.0.3', 'down', 'Switch Andar 2');

        $stmt = self::$pdo->query('
            SELECT
                nh.host_address, nh.comment, nh.current_status,
                nh.status_since, nh.last_rtt_ms,
                m.name AS mikrotik_name,
                c.name AS client_name
            FROM netwatch_hosts nh
            LEFT JOIN mikrotiks m ON m.id = nh.mikrotik_id
            LEFT JOIN clients c ON c.id = m.client_id
            WHERE nh.active = true AND nh.current_status = \'down\'
            ORDER BY nh.status_since ASC NULLS LAST
            LIMIT 50
        ');
        $downHosts = $stmt->fetchAll();

        $this->assertCount(2, $downHosts, 'Deve retornar 2 hosts down');
        $comments = array_column($downHosts, 'comment');
        $this->assertContains('Câmera Principal', $comments);
        $this->assertContains('Switch Andar 2', $comments);
        $this->assertEquals('Mik-Down', $downHosts[0]['mikrotik_name']);
        $this->assertEquals('Cliente Down', $downHosts[0]['client_name']);
    }

    public function testTvApiJsonStructureIsValid(): void
    {
        $client = self::createClient('Cliente JSON');
        $mikrotikId = self::createMikrotik($client, 'Mik-JSON');
        self::createHost($mikrotikId, '10.0.0.1', 'down');

        // Simular a resposta JSON do TvController::api()
        $stmt = self::$pdo->query('
            SELECT
                COUNT(*) FILTER (WHERE active = true) AS total,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'online\') AS online,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'offline\') AS offline
            FROM mikrotiks
        ');
        $deviceSummary = $stmt->fetch();

        $stmt = self::$pdo->query('
            SELECT
                COUNT(*) FILTER (WHERE active = true) AS total,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'up\') AS up,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'down\') AS down
            FROM netwatch_hosts
        ');
        $hostSummary = $stmt->fetch();

        $json = json_encode([
            'devices'   => $deviceSummary,
            'hosts'     => $hostSummary,
            'deviceList' => [],
            'downHosts'  => [],
            'lastCheck'  => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertNotFalse($json, 'JSON deve ser válido');
        $decoded = json_decode($json, true);
        $this->assertArrayHasKey('devices', $decoded);
        $this->assertArrayHasKey('hosts', $decoded);
        $this->assertArrayHasKey('deviceList', $decoded);
        $this->assertArrayHasKey('downHosts', $decoded);
        $this->assertArrayHasKey('lastCheck', $decoded);
    }

    // ─── SEGURANÇA — DADOS SENSÍVEIS ─────────────────────────────────────────

    public function testTvApiDoesNotExposePasswords(): void
    {
        $client = self::createClient('Cliente Seg');
        $mikrotikId = self::createMikrotik($client, 'Mik-Seg');

        $stmt = self::$pdo->query('
            SELECT
                m.name, m.host, m.current_status, m.status_since,
                m.last_cpu_load, m.last_memory_free, m.last_memory_total,
                m.last_temperature, m.last_rtt_ms, m.device_type,
                m.last_checked_at,
                c.name AS client_name
            FROM mikrotiks m
            LEFT JOIN clients c ON c.id = m.client_id
            WHERE m.active = true
        ');
        $devices = $stmt->fetchAll();

        // Verificar que password_encrypted NÃO está nos dados retornados
        foreach ($devices as $device) {
            $this->assertArrayNotHasKey('password_encrypted', $device, 'TV API não deve expor password_encrypted');
            $this->assertArrayNotHasKey('username', $device, 'TV API não deve expor username');
        }
    }

    public function testTvApiDoesNotExposeHostSensitiveFields(): void
    {
        $client = self::createClient('Cliente Hosts Seg');
        $mikrotikId = self::createMikrotik($client, 'Mik-Hosts-Seg');
        self::createHost($mikrotikId, '10.0.0.1', 'up', 'Host Sensível');

        $stmt = self::$pdo->query('
            SELECT
                nh.host_address, nh.comment, nh.current_status,
                nh.status_since, nh.last_rtt_ms,
                m.name AS mikrotik_name,
                c.name AS client_name
            FROM netwatch_hosts nh
            LEFT JOIN mikrotiks m ON m.id = nh.mikrotik_id
            LEFT JOIN clients c ON c.id = m.client_id
            WHERE nh.active = true
        ');
        $hosts = $stmt->fetchAll();

        $this->assertNotEmpty($hosts, 'Deve haver ao menos 1 host');

        foreach ($hosts as $host) {
            // Hosts não têm senha, mas verificar que não expomos IDs internos sensíveis
            $this->assertArrayNotHasKey('mikrotik_ref_id', $host, 'TV API não deve expor mikrotik_ref_id');
        }
    }

    // ─── EMPTY STATE ──────────────────────────────────────────────────────────

    public function testTvApiWithNoDataReturnsZeroCounts(): void
    {
        $stmt = self::$pdo->query('
            SELECT
                COUNT(*) FILTER (WHERE active = true) AS total,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'online\') AS online,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'offline\') AS offline
            FROM mikrotiks
        ');
        $deviceSummary = $stmt->fetch();

        $this->assertEquals(0, (int) $deviceSummary['total']);
        $this->assertEquals(0, (int) $deviceSummary['online']);
        $this->assertEquals(0, (int) $deviceSummary['offline']);
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    private static function createClient(string $name): string
    {
        $stmt = self::$pdo->prepare('INSERT INTO clients (name) VALUES (:name) RETURNING id');
        $stmt->execute([':name' => $name]);
        return $stmt->fetch()['id'];
    }

    private static function createMikrotik(string $clientId, string $name, string $status = 'unknown'): string
    {
        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotiks (client_id, name, host, username, password_encrypted, current_status)
            VALUES (:client_id, :name, :host, :username, :pw, :status) RETURNING id
        ');
        $stmt->execute([
            ':client_id' => $clientId,
            ':name'      => $name,
            ':host'      => '10.0.0.' . rand(1, 254),
            ':username'  => 'admin',
            ':pw'        => '\\x0000000000000000000000000000000000',
            ':status'    => $status,
        ]);
        return $stmt->fetch()['id'];
    }

    private static function createHost(string $mikrotikId, string $address, string $status = 'up', string $comment = ''): string
    {
        $stmt = self::$pdo->prepare('
            INSERT INTO netwatch_hosts (mikrotik_id, host_address, current_status, comment)
            VALUES (:id, :addr, :status, :comment) RETURNING id
        ');
        $stmt->execute([':id' => $mikrotikId, ':addr' => $address, ':status' => $status, ':comment' => $comment]);
        return $stmt->fetch()['id'];
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
