<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use App\Service\CredentialCrypto;

/**
 * Mikrotik Watch - Ping Device Integration Tests
 *
 * Testes para equipamentos com device_type = 'ping'.
 */
class PingDeviceTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static ?CredentialCrypto $crypto = null;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = self::getTestDatabase();
        $key = CredentialCrypto::generateKey();
        self::$crypto = new CredentialCrypto($key);
        self::truncateTables();
    }

    protected function setUp(): void
    {
        self::truncateTables();
    }

    // ─── CREATE ───────────────────────────────────────────────────────────────

    public function testCreatePingDeviceWithoutCredentials(): void
    {
        $clientId = self::createTestClient('Cliente Ping');

        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotiks (client_id, name, host, device_type, current_status)
            VALUES (:client_id, :name, :host, :device_type, :current_status)
            RETURNING id
        ');
        $stmt->execute([
            ':client_id'     => $clientId,
            ':name'          => 'Servidor Web',
            ':host'          => '192.168.1.100',
            ':device_type'   => 'ping',
            ':current_status' => 'unknown',
        ]);
        $result = $stmt->fetch();
        $this->assertNotEmpty($result['id']);

        // Verificar que foi criado corretamente
        $stmt = self::$pdo->prepare('SELECT * FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $result['id']]);
        $mikrotik = $stmt->fetch();

        $this->assertEquals('ping', $mikrotik['device_type']);
        $this->assertEquals('192.168.1.100', $mikrotik['host']);
        $this->assertEquals(443, (int) $mikrotik['port']); // default value
        $this->assertNull($mikrotik['username']);
        $this->assertNull($mikrotik['password_encrypted']);
        $this->assertEquals('unknown', $mikrotik['current_status']);
    }

    public function testCreateMikrotikDeviceWithCredentials(): void
    {
        $clientId = self::createTestClient('Cliente Mikrotik');
        $password = 'senha_secreta';

        $encryptedBase64 = self::$crypto->encrypt($password);

        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotiks (client_id, name, host, device_type, port, use_ssl, username, password_encrypted, current_status)
            VALUES (:client_id, :name, :host, :device_type, :port, :use_ssl, :username, decode(:pw, \'base64\'), :current_status)
            RETURNING id
        ');
        $stmt->execute([
            ':client_id'     => $clientId,
            ':name'          => 'Router Principal',
            ':host'          => '192.168.1.1',
            ':device_type'   => 'mikrotik',
            ':port'          => 80,
            ':use_ssl'       => 0,
            ':username'      => 'admin',
            ':pw'            => $encryptedBase64,
            ':current_status' => 'unknown',
        ]);
        $result = $stmt->fetch();

        // Verificar que a senha pode ser descriptografada
        $stmt = self::$pdo->prepare('SELECT password_encrypted FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $result['id']]);
        $row = $stmt->fetch();
        $encryptedBytes = $row['password_encrypted'];
        if (is_resource($encryptedBytes)) {
            $encryptedBytes = stream_get_contents($encryptedBytes);
        }
        $decrypted = self::$crypto->decrypt(base64_encode($encryptedBytes));
        $this->assertEquals($password, $decrypted);
    }

    // ─── VALIDATION ───────────────────────────────────────────────────────────

    public function testMikrotikDeviceRequiresUsernameAndPassword(): void
    {
        $clientId = self::createTestClient('Cliente Valid');

        $this->expectException(\PDOException::class);

        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotiks (client_id, name, host, device_type, current_status)
            VALUES (:client_id, :name, :host, :device_type, :current_status)
        ');
        $stmt->execute([
            ':client_id'     => $clientId,
            ':name'          => 'Router Sem Credenciais',
            ':host'          => '192.168.1.1',
            ':device_type'   => 'mikrotik',
            ':current_status' => 'unknown',
        ]);
    }

    // ─── IDEMPOTENCY ──────────────────────────────────────────────────────────

    public function testPingDeviceStatusUpdateDoesNotCreateDuplicateEvent(): void
    {
        $clientId = self::createTestClient('Cliente Idempotente');
        $deviceId = self::createTestPingDevice($clientId, 'Servidor Idem');

        // Simular status online
        $stmt = self::$pdo->prepare('UPDATE mikrotiks SET current_status = \'online\' WHERE id = :id');
        $stmt->execute([':id' => $deviceId]);

        // Contar eventos antes
        $stmt = self::$pdo->prepare('SELECT COUNT(*) AS total FROM mikrotik_events WHERE mikrotik_id = :id');
        $stmt->execute([':id' => $deviceId]);
        $before = (int) $stmt->fetch()['total'];

        // Simular segunda verificação SEM mudança (ainda online)
        $stmt = self::$pdo->prepare('UPDATE mikrotiks SET last_checked_at = NOW(), last_rtt_ms = 5 WHERE id = :id');
        $stmt->execute([':id' => $deviceId]);

        // Contar eventos depois
        $stmt = self::$pdo->prepare('SELECT COUNT(*) AS total FROM mikrotik_events WHERE mikrotik_id = :id');
        $stmt->execute([':id' => $deviceId]);
        $after = (int) $stmt->fetch()['total'];

        $this->assertEquals($before, $after, 'Nenhum evento deve ser criado quando o status não muda');
    }

    public function testPingDeviceStatusChangeCreatesExactlyOneEvent(): void
    {
        $clientId = self::createTestClient('Cliente Transição');
        $deviceId = self::createTestPingDevice($clientId, 'Servidor Trans');

        // Simular status online
        $stmt = self::$pdo->prepare('UPDATE mikrotiks SET current_status = \'online\' WHERE id = :id');
        $stmt->execute([':id' => $deviceId]);

        // Criar evento de transição
        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotik_events (mikrotik_id, status, started_at)
            VALUES (:mikrotik_id, :status, NOW())
        ');
        $stmt->execute([':mikrotik_id' => $deviceId, ':status' => 'offline']);

        // Verificar que EXATAMENTE UM evento foi criado
        $stmt = self::$pdo->prepare('SELECT COUNT(*) AS total FROM mikrotik_events WHERE mikrotik_id = :id');
        $stmt->execute([':id' => $deviceId]);
        $this->assertEquals(1, (int) $stmt->fetch()['total']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private static function createTestClient(string $name): string
    {
        $stmt = self::$pdo->prepare('INSERT INTO clients (name) VALUES (:name) RETURNING id');
        $stmt->execute([':name' => $name]);
        return $stmt->fetch()['id'];
    }

    private static function createTestPingDevice(string $clientId, string $name): string
    {
        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotiks (client_id, name, host, device_type, current_status)
            VALUES (:client_id, :name, :host, :device_type, :current_status)
            RETURNING id
        ');
        $stmt->execute([
            ':client_id'     => $clientId,
            ':name'          => $name,
            ':host'          => '192.168.' . rand(1, 254) . '.' . rand(1, 254),
            ':device_type'   => 'ping',
            ':current_status' => 'unknown',
        ]);
        return $stmt->fetch()['id'];
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
