<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Mikrotik Watch - Netwatch Sync Integration Tests
 *
 * Testa a lógica de sincronização do Netwatch:
 * - Transições de status (up→down, down→up) geram exatamente 1 evento
 * - Sem mudança de status NÃO gera evento duplicado
 * - Inserção de hosts novos
 * - Soft delete de hosts ausentes na API
 */
class NetwatchSyncTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static ?string $testClientId = null;
    private static ?string $testMikrotikId = null;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = self::getTestDatabase();
        self::truncateTables();
        self::seedTestData();
    }

    public static function tearDownAfterClass(): void
    {
        self::truncateTables();
    }

    protected function setUp(): void
    {
        // Limpar apenas netwatch_hosts e netwatch_events entre os testes
        self::$pdo->exec('TRUNCATE TABLE netwatch_events, netwatch_hosts CASCADE');
    }

    // ─── Transição up → down ─────────────────────────────────────────────────

    public function testTransitionUpToDownCreatesExactlyOneEvent(): void
    {
        $hostId = self::createNetwatchHost(self::$testMikrotikId, '10.0.0.1', 'up');

        // Simular coleta: host muda de up para down
        self::simulateStatusChange($hostId, 'down');

        // Deve existir exatamente 1 evento
        $events = self::getEvents($hostId);
        $this->assertCount(1, $events);
        $this->assertEquals('down', $events[0]['status']);
        $this->assertNull($events[0]['ended_at']); // evento aberto
    }

    // ─── Transição down → up ─────────────────────────────────────────────────

    public function testTransitionDownToUpCreatesExactlyOneEvent(): void
    {
        $hostId = self::createNetwatchHost(self::$testMikrotikId, '10.0.0.2', 'down');

        // Simular coleta: host muda de down para up
        self::simulateStatusChange($hostId, 'up');

        // Deve existir exatamente 1 evento
        $events = self::getEvents($hostId);
        $this->assertCount(1, $events);
        $this->assertEquals('up', $events[0]['status']);
        $this->assertNull($events[0]['ended_at']);
    }

    // ─── Sem mudança de status ───────────────────────────────────────────────

    public function testNoStatusChangeDoesNotCreateDuplicateEvent(): void
    {
        $hostId = self::createNetwatchHost(self::$testMikrotikId, '10.0.0.3', 'up');

        // Primeira coleta: status continua up
        self::simulateStatusChange($hostId, 'up');

        $eventsAfterFirst = self::getEvents($hostId);
        $this->assertCount(0, $eventsAfterFirst); // Sem transição = sem evento

        // Segunda coleta: status continua up
        self::simulateStatusChange($hostId, 'up');

        $eventsAfterSecond = self::getEvents($hostId);
        $this->assertCount(0, $eventsAfterSecond); // Ainda sem evento
    }

    // ─── Múltiplas transições ────────────────────────────────────────────────

    public function testMultipleTransitionsGenerateCorrectEvents(): void
    {
        $hostId = self::createNetwatchHost(self::$testMikrotikId, '10.0.0.4', 'up');

        // up → down (evento 1)
        self::simulateStatusChange($hostId, 'down');
        $events = self::getEvents($hostId);
        $this->assertCount(1, $events);
        $this->assertEquals('down', $events[0]['status']);

        // down → up (evento 2)
        self::simulateStatusChange($hostId, 'up');
        $events = self::getEvents($hostId);
        $this->assertCount(2, $events);
        // Evento mais recente primeiro
        $this->assertEquals('up', $events[0]['status']);
        $this->assertEquals('down', $events[1]['status']);

        // Verificar que o primeiro evento foi fechado
        $this->assertNotNull($events[1]['ended_at']);
        $this->assertGreaterThanOrEqual(0, (int) $events[1]['duration_seconds']);
    }

    // ─── Inserção de host novo ───────────────────────────────────────────────

    public function testNewHostInsertedWithCorrectStatus(): void
    {
        $mikrotikId = self::$testMikrotikId;

        // Simular inserção de host novo (como o collect_netwatch faz)
        $stmt = self::$pdo->prepare('
            INSERT INTO netwatch_hosts (mikrotik_id, host_address, comment, mikrotik_ref_id, current_status, status_since, last_checked_at, active)
            VALUES (:mikrotik_id, :host_address, :comment, :mikrotik_ref_id, :status, now(), now(), true)
            RETURNING id
        ');
        $stmt->execute([
            ':mikrotik_id'     => $mikrotikId,
            ':host_address'    => '10.0.0.99',
            ':comment'         => 'Host novo',
            ':mikrotik_ref_id' => '*NEW1',
            ':status'          => 'up',
        ]);
        $hostId = $stmt->fetch()['id'];

        // Verificar que foi inserido
        $stmt = self::$pdo->prepare('SELECT * FROM netwatch_hosts WHERE id = :id');
        $stmt->execute([':id' => $hostId]);
        $host = $stmt->fetch();

        $this->assertNotEmpty($host);
        $this->assertEquals('10.0.0.99', $host['host_address']);
        $this->assertEquals('up', $host['current_status']);
        $this->assertTrue((bool) $host['active']);
    }

    // ─── Soft delete de host ausente ─────────────────────────────────────────

    public function testAbsentHostDeactivated(): void
    {
        $hostId = self::createNetwatchHost(self::$testMikrotikId, '10.0.0.50', 'up');

        // Simular soft delete (host não está mais na API)
        $stmt = self::$pdo->prepare('
            UPDATE netwatch_hosts SET active = false, current_status = \'unknown\' WHERE id = :id
        ');
        $stmt->execute([':id' => $hostId]);

        $stmt = self::$pdo->prepare('SELECT active, current_status FROM netwatch_hosts WHERE id = :id');
        $stmt->execute([':id' => $hostId]);
        $host = $stmt->fetch();

        $this->assertFalse((bool) $host['active']);
        $this->assertEquals('unknown', $host['current_status']);
    }

    // ─── Mikrotik status update ──────────────────────────────────────────────

    public function testMikrotikStatusUpdatedOnSuccess(): void
    {
        $mikrotikId = self::$testMikrotikId;

        // Status inicial
        self::updateMikrotikStatus($mikrotikId, 'unknown');

        // Simular sucesso (online)
        self::updateMikrotikStatus($mikrotikId, 'online');

        $stmt = self::$pdo->prepare('SELECT current_status FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $mikrotik = $stmt->fetch();

        $this->assertEquals('online', $mikrotik['current_status']);
    }

    public function testMikrotikStatusUpdatedOnFailure(): void
    {
        $mikrotikId = self::$testMikrotikId;

        // Status inicial: online
        self::updateMikrotikStatus($mikrotikId, 'online');

        // Simular falha (offline)
        self::updateMikrotikStatus($mikrotikId, 'offline');

        $stmt = self::$pdo->prepare('SELECT current_status FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $mikrotik = $stmt->fetch();

        $this->assertEquals('offline', $mikrotik['current_status']);
    }

    public function testMikrotikStatusSinceUpdatesOnTransition(): void
    {
        $mikrotikId = self::$testMikrotikId;

        // Status inicial: online
        self::updateMikrotikStatus($mikrotikId, 'online');

        $stmt = self::$pdo->prepare('SELECT status_since FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $before = $stmt->fetch();
        $statusSinceBefore = $before['status_since'];

        // Transição para offline
        sleep(1);
        self::updateMikrotikStatus($mikrotikId, 'offline');

        $stmt = self::$pdo->prepare('SELECT status_since FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $after = $stmt->fetch();

        // status_since deve ter mudado
        $this->assertNotEquals($statusSinceBefore, $after['status_since']);
    }

    public function testMikrotikStatusSinceStaysSameWhenNoTransition(): void
    {
        $mikrotikId = self::$testMikrotikId;

        // Status inicial: online
        self::updateMikrotikStatus($mikrotikId, 'online');

        $stmt = self::$pdo->prepare('SELECT status_since FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $before = $stmt->fetch();
        $statusSinceBefore = $before['status_since'];

        // Mesmo status (online → online)
        sleep(1);
        self::updateMikrotikStatus($mikrotikId, 'online');

        $stmt = self::$pdo->prepare('SELECT status_since FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $after = $stmt->fetch();

        // status_since NÃO deve ter mudado
        $this->assertEquals($statusSinceBefore, $after['status_since']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Simula a mudança de status de um host (como o collect_netwatch faz).
     */
    private static function simulateStatusChange(string $hostId, string $newStatus): void
    {
        // Buscar status atual
        $stmt = self::$pdo->prepare('SELECT current_status FROM netwatch_hosts WHERE id = :id');
        $stmt->execute([':id' => $hostId]);
        $host = $stmt->fetch();
        $oldStatus = $host['current_status'];

        // Atualizar status do host
        $stmt = self::$pdo->prepare('
            UPDATE netwatch_hosts SET current_status = :status, last_checked_at = now() WHERE id = :id
        ');
        $stmt->execute([':status' => $newStatus, ':id' => $hostId]);

        // Registrar evento se status mudou
        if ($newStatus !== $oldStatus && $newStatus !== 'unknown') {
            // Fechar evento aberto
            $stmt = self::$pdo->prepare('
                UPDATE netwatch_events SET ended_at = now(),
                    duration_seconds = EXTRACT(EPOCH FROM (now() - started_at))::INTEGER
                WHERE netwatch_host_id = :host_id AND ended_at IS NULL
            ');
            $stmt->execute([':host_id' => $hostId]);

            // Criar novo evento
            $stmt = self::$pdo->prepare('
                INSERT INTO netwatch_events (netwatch_host_id, status, started_at)
                VALUES (:host_id, :status, now())
            ');
            $stmt->execute([':host_id' => $hostId, ':status' => $newStatus]);
        }
    }

    /**
     * Atualiza o status de um Mikrotik (como o collect_netwatch faz).
     */
    private static function updateMikrotikStatus(string $mikrotikId, string $status): void
    {
        $stmt = self::$pdo->prepare('
            UPDATE mikrotiks SET
                current_status = CAST(:status AS VARCHAR(10)),
                status_since = CASE WHEN current_status != :status2 THEN now() ELSE status_since END,
                last_checked_at = now()
            WHERE id = :id
        ');
        $stmt->execute([':status' => $status, ':status2' => $status, ':id' => $mikrotikId]);
    }

    private static function getEvents(string $hostId): array
    {
        $stmt = self::$pdo->prepare('
            SELECT * FROM netwatch_events WHERE netwatch_host_id = :host_id ORDER BY started_at DESC
        ');
        $stmt->execute([':host_id' => $hostId]);
        return $stmt->fetchAll();
    }

    private static function createNetwatchHost(string $mikrotikId, string $address, string $status): string
    {
        $stmt = self::$pdo->prepare('
            INSERT INTO netwatch_hosts (mikrotik_id, host_address, current_status, status_since, last_checked_at, active)
            VALUES (:mikrotik_id, :address, :status, now(), now(), true)
            RETURNING id
        ');
        $stmt->execute([':mikrotik_id' => $mikrotikId, ':address' => $address, ':status' => $status]);
        return $stmt->fetch()['id'];
    }

    private static function seedTestData(): void
    {
        // Criar cliente
        $stmt = self::$pdo->prepare('INSERT INTO clients (name) VALUES (:name) RETURNING id');
        $stmt->execute([':name' => 'Test Client']);
        self::$testClientId = $stmt->fetch()['id'];

        // Criar Mikrotik
        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotiks (client_id, name, host, username, password_encrypted, current_status)
            VALUES (:client_id, :name, :host, :username, :pw, :status)
            RETURNING id
        ');
        $stmt->execute([
            ':client_id' => self::$testClientId,
            ':name'      => 'Test Mikrotik',
            ':host'      => '192.168.88.1',
            ':username'  => 'admin',
            ':pw'        => '\\x00',
            ':status'    => 'unknown',
        ]);
        self::$testMikrotikId = $stmt->fetch()['id'];
    }

    private static function truncateTables(): void
    {
        self::$pdo->exec('
            TRUNCATE TABLE
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
