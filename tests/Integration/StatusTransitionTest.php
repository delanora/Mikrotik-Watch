<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\StatusTransition;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Mikrotik Watch - StatusTransition Integration Tests
 *
 * Testa o mecanismo de debounce (confirmação por falhas consecutivas):
 * - 1 falha isolada: não muda status
 * - 2 falhas consecutivas: muda para warning
 * - 3 falhas consecutivas: muda para offline/down, cria evento com started_at = primeira falha
 * - 4ª, 5ª falha: idempotente (não cria evento duplicado)
 * - Sucesso após 1-2 falhas: reseta contadores
 * - Sucesso após offline: fecha evento, volta para online imediatamente
 */
class StatusTransitionTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static ?string $testClientId = null;
    private static ?string $testMikrotikId = null;
    private static ?string $testNetwatchHostId = null;

    private const THRESHOLD = 3;

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
        self::$pdo->exec('TRUNCATE TABLE netwatch_events, mikrotik_events CASCADE');
        // Resetar mikrotik para estado limpo
        self::$pdo->prepare('UPDATE mikrotiks SET current_status = \'online\', consecutive_failures = 0, first_failure_at = NULL, status_since = now(), last_checked_at = now() WHERE id = :id')->execute([':id' => self::$testMikrotikId]);
        // Resetar netwatch host para estado limpo
        self::$pdo->prepare('UPDATE netwatch_hosts SET current_status = \'up\', consecutive_failures = 0, first_failure_at = NULL, status_since = now(), last_checked_at = now() WHERE id = :id')->execute([':id' => self::$testNetwatchHostId]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // MIKROTIKS
    // ═══════════════════════════════════════════════════════════════════════════

    public function testMikrotikOneFailureStaysOnline(): void
    {
        $id = self::$testMikrotikId;

        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);

        $m = self::getMikrotik($id);
        $this->assertEquals('online', $m['current_status']);
        $this->assertEquals(1, (int) $m['consecutive_failures']);
        $this->assertNotNull($m['first_failure_at']);

        // Nenhum evento criado
        $events = self::getMikrotikEvents($id);
        $this->assertCount(0, $events);
    }

    public function testMikrotikTwoFailuresChangesToWarning(): void
    {
        $id = self::$testMikrotikId;

        // 1ª falha
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);
        // 2ª falha
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);

        $m = self::getMikrotik($id);
        $this->assertEquals('warning', $m['current_status']);
        $this->assertEquals(2, (int) $m['consecutive_failures']);

        // Nenhum evento criado (warning é estado soft)
        $events = self::getMikrotikEvents($id);
        $this->assertCount(0, $events);
    }

    public function testMikrotikThreeFailuresChangesToOfflineWithCorrectStartedAt(): void
    {
        $id = self::$testMikrotikId;

        // 1ª falha — first_failure_at é registrado
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);
        $firstFailure = self::getMikrotik($id)['first_failure_at'];
        $this->assertNotNull($firstFailure);

        // 2ª falha
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);
        // 3ª falha — threshold atingido
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);

        $m = self::getMikrotik($id);
        $this->assertEquals('offline', $m['current_status']);
        $this->assertEquals(3, (int) $m['consecutive_failures']);

        // Evento criado com started_at = primeira falha
        $events = self::getMikrotikEvents($id);
        $this->assertCount(1, $events);
        $this->assertEquals('offline', $events[0]['status']);
        $this->assertNull($events[0]['ended_at']);
        // started_at deve ser igual ao first_failure_at
        $this->assertEquals(
            date('Y-m-d H:i:s', strtotime($firstFailure)),
            date('Y-m-d H:i:s', strtotime($events[0]['started_at']))
        );
    }

    public function testMikrotikFourthAndFifthFailureAreIdempotent(): void
    {
        $id = self::$testMikrotikId;

        // 3 falhas para chegar ao offline
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);

        // 4ª falha
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);
        // 5ª falha
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);

        // Ainda EXATAMENTE 1 evento (idempotente)
        $events = self::getMikrotikEvents($id);
        $this->assertCount(1, $events);
        $this->assertEquals('offline', $events[0]['status']);

        $m = self::getMikrotik($id);
        $this->assertEquals(5, (int) $m['consecutive_failures']);
    }

    public function testMikrotikSuccessAfterOneOrTwoFailuresResetsCounters(): void
    {
        $id = self::$testMikrotikId;

        // 1ª falha
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);
        // 2ª falha (agora em warning)
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);

        // Sucesso — deve resetar
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, true, 'online', 'offline', self::THRESHOLD);

        $m = self::getMikrotik($id);
        $this->assertEquals('online', $m['current_status']);
        $this->assertEquals(0, (int) $m['consecutive_failures']);
        $this->assertNull($m['first_failure_at']);

        // Nenhum evento (nunca chegou a offline)
        $events = self::getMikrotikEvents($id);
        $this->assertCount(0, $events);
    }

    public function testMikrotikSuccessAfterOfflineClosesEventAndReturnsOnline(): void
    {
        $id = self::$testMikrotikId;

        // 3 falhas → offline
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, false, 'online', 'offline', self::THRESHOLD);

        $this->assertEquals('offline', self::getMikrotik($id)['current_status']);

        // Sucesso — deve fechar evento e voltar para online
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, true, 'online', 'offline', self::THRESHOLD);

        $m = self::getMikrotik($id);
        $this->assertEquals('online', $m['current_status']);
        $this->assertEquals(0, (int) $m['consecutive_failures']);
        $this->assertNull($m['first_failure_at']);

        // Evento fechado
        $events = self::getMikrotikEvents($id);
        $this->assertCount(1, $events);
        $this->assertEquals('offline', $events[0]['status']);
        $this->assertNotNull($events[0]['ended_at']);
        $this->assertGreaterThanOrEqual(0, (int) $events[0]['duration_seconds']);
    }

    public function testMikrotikAlreadyOnlineWithNoFailuresOnlyUpdatesLastChecked(): void
    {
        $id = self::$testMikrotikId;

        $before = self::getMikrotik($id)['last_checked_at'];

        // Sucesso quando já está online sem falhas
        StatusTransition::evaluate(self::$pdo, 'mikrotiks', 'mikrotik_events', $id, true, 'online', 'offline', self::THRESHOLD);

        $after = self::getMikrotik($id)['last_checked_at'];

        $this->assertEquals('online', self::getMikrotik($id)['current_status']);
        // last_checked_at deve ter sido atualizado
        $this->assertNotEquals($before, $after);

        // Nenhum evento
        $events = self::getMikrotikEvents($id);
        $this->assertCount(0, $events);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // NETWATCH HOSTS
    // ═══════════════════════════════════════════════════════════════════════════

    public function testNetwatchOneFailureStaysUp(): void
    {
        $id = self::$testNetwatchHostId;

        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, false, 'up', 'down', self::THRESHOLD);

        $h = self::getNetwatchHost($id);
        $this->assertEquals('up', $h['current_status']);
        $this->assertEquals(1, (int) $h['consecutive_failures']);

        $events = self::getNetwatchEvents($id);
        $this->assertCount(0, $events);
    }

    public function testNetwatchTwoFailuresChangesToWarning(): void
    {
        $id = self::$testNetwatchHostId;

        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, false, 'up', 'down', self::THRESHOLD);
        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, false, 'up', 'down', self::THRESHOLD);

        $h = self::getNetwatchHost($id);
        $this->assertEquals('warning', $h['current_status']);
        $this->assertEquals(2, (int) $h['consecutive_failures']);

        $events = self::getNetwatchEvents($id);
        $this->assertCount(0, $events);
    }

    public function testNetwatchThreeFailuresChangesToDownWithCorrectStartedAt(): void
    {
        $id = self::$testNetwatchHostId;

        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, false, 'up', 'down', self::THRESHOLD);
        $firstFailure = self::getNetwatchHost($id)['first_failure_at'];

        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, false, 'up', 'down', self::THRESHOLD);
        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, false, 'up', 'down', self::THRESHOLD);

        $h = self::getNetwatchHost($id);
        $this->assertEquals('down', $h['current_status']);

        $events = self::getNetwatchEvents($id);
        $this->assertCount(1, $events);
        $this->assertEquals('down', $events[0]['status']);
        $this->assertEquals(
            date('Y-m-d H:i:s', strtotime($firstFailure)),
            date('Y-m-d H:i:s', strtotime($events[0]['started_at']))
        );
    }

    public function testNetwatchSuccessAfterDownClosesEventAndReturnsUp(): void
    {
        $id = self::$testNetwatchHostId;

        // 3 falhas → down
        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, false, 'up', 'down', self::THRESHOLD);
        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, false, 'up', 'down', self::THRESHOLD);
        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, false, 'up', 'down', self::THRESHOLD);

        // Sucesso → volta para up imediatamente
        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, true, 'up', 'down', self::THRESHOLD);

        $h = self::getNetwatchHost($id);
        $this->assertEquals('up', $h['current_status']);
        $this->assertEquals(0, (int) $h['consecutive_failures']);

        $events = self::getNetwatchEvents($id);
        $this->assertCount(1, $events);
        $this->assertNotNull($events[0]['ended_at']);
    }

    public function testNetwatchIdempotencyOnRepeatedOffline(): void
    {
        $id = self::$testNetwatchHostId;

        // 3 falhas → down
        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, false, 'up', 'down', self::THRESHOLD);
        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, false, 'up', 'down', self::THRESHOLD);
        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, false, 'up', 'down', self::THRESHOLD);

        // 4ª e 5ª falha — não deve criar eventos extras
        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, false, 'up', 'down', self::THRESHOLD);
        StatusTransition::evaluate(self::$pdo, 'netwatch_hosts', 'netwatch_events', $id, false, 'up', 'down', self::THRESHOLD);

        $events = self::getNetwatchEvents($id);
        $this->assertCount(1, $events);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════════

    private static function getMikrotik(int $id): array
    {
        $stmt = self::$pdo->prepare('SELECT * FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    private static function getNetwatchHost(int $id): array
    {
        $stmt = self::$pdo->prepare('SELECT * FROM netwatch_hosts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    private static function getMikrotikEvents(int $mikrotikId): array
    {
        $stmt = self::$pdo->prepare('SELECT * FROM mikrotik_events WHERE mikrotik_id = :id ORDER BY started_at DESC');
        $stmt->execute([':id' => $mikrotikId]);
        return $stmt->fetchAll();
    }

    private static function getNetwatchEvents(int $hostId): array
    {
        $stmt = self::$pdo->prepare('SELECT * FROM netwatch_events WHERE netwatch_host_id = :id ORDER BY started_at DESC');
        $stmt->execute([':id' => $hostId]);
        return $stmt->fetchAll();
    }

    private static function seedTestData(): void
    {
        // Criar cliente
        $stmt = self::$pdo->prepare('INSERT INTO clients (name) VALUES (:name) RETURNING id');
        $stmt->execute([':name' => 'Test Client']);
        self::$testClientId = $stmt->fetch()['id'];

        // Criar Mikrotik
        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotiks (client_id, name, host, username, password_encrypted, current_status, consecutive_failures, last_checked_at)
            VALUES (:client_id, :name, :host, :username, :pw, :status, 0, now())
            RETURNING id
        ');
        $stmt->execute([
            ':client_id' => self::$testClientId,
            ':name'      => 'Test Mikrotik',
            ':host'      => '192.168.88.1',
            ':username'  => 'admin',
            ':pw'        => '\\x00',
            ':status'    => 'online',
        ]);
        self::$testMikrotikId = $stmt->fetch()['id'];

        // Criar Netwatch Host
        $stmt = self::$pdo->prepare('
            INSERT INTO netwatch_hosts (mikrotik_id, host_address, current_status, consecutive_failures, last_checked_at, active)
            VALUES (:mikrotik_id, :address, :status, 0, now(), true)
            RETURNING id
        ');
        $stmt->execute([
            ':mikrotik_id' => self::$testMikrotikId,
            ':address'     => '10.0.0.1',
            ':status'      => 'up',
        ]);
        self::$testNetwatchHostId = $stmt->fetch()['id'];
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
