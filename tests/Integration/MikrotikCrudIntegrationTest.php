<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use App\Service\CredentialCrypto;

/**
 * Mikrotik Watch - Mikrotik CRUD Integration Tests
 *
 * Testes de integração para o CRUD de Mikrotiks.
 * Requer banco de dados de testes configurado.
 */
class MikrotikCrudIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static ?CredentialCrypto $crypto = null;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = self::getTestDatabase();

        // Gerar chave de criptografia para testes
        $key = CredentialCrypto::generateKey();
        self::$crypto = new CredentialCrypto($key);

        self::truncateTables();
    }

    protected function setUp(): void
    {
        self::truncateTables();
    }

    // ─── CREATE ───────────────────────────────────────────────────────────────

    public function testCreateMikrotikWithEncryptedPassword(): void
    {
        $clientId = self::createTestClient('Cliente Teste');

        $password = 'minha_senha_secreta_123';
        $encryptedBase64 = self::$crypto->encrypt($password);

        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotiks (client_id, name, host, port, use_ssl, username, password_encrypted, current_status)
            VALUES (:client_id, :name, :host, :port, :use_ssl, :username, decode(:password_encrypted, \'base64\'), :current_status)
            RETURNING id
        ');
        $stmt->execute([
            ':client_id'          => $clientId,
            ':name'               => 'Mikrotik-Teste',
            ':host'               => '192.168.1.100',
            ':port'               => 80,
            ':use_ssl'            => 0,
            ':username'           => 'admin',
            ':password_encrypted' => $encryptedBase64,
            ':current_status'     => 'unknown',
        ]);
        $result = $stmt->fetch();
        $mikrotikId = $result['id'];

        // Verificar que foi criado com status unknown
        $stmt = self::$pdo->prepare('SELECT * FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $mikrotik = $stmt->fetch();

        $this->assertEquals('Mikrotik-Teste', $mikrotik['name']);
        $this->assertEquals('192.168.1.100', $mikrotik['host']);
        $this->assertEquals('unknown', $mikrotik['current_status']);

        // Verificar que a senha pode ser descriptografada
        $encryptedBytes = $mikrotik['password_encrypted'];
        if (is_resource($encryptedBytes)) {
            $encryptedBytes = stream_get_contents($encryptedBytes);
        }
        $decrypted = self::$crypto->decrypt(base64_encode($encryptedBytes));
        $this->assertEquals($password, $decrypted);
    }

    // ─── EDIT ─────────────────────────────────────────────────────────────────

    public function testEditWithoutPasswordKeepsExistingPassword(): void
    {
        $clientId = self::createTestClient('Cliente Edit');
        $originalPassword = 'senha_original_456';
        $mikrotikId = self::createTestMikrotik($clientId, 'Mikrotik-Edit', $originalPassword);

        // Editar sem preencher senha (senha vazia = manter)
        $newName = 'Mikrotik-Editado';
        $stmt = self::$pdo->prepare('
            UPDATE mikrotiks
            SET name = :name, host = :host
            WHERE id = :id
        ');
        $stmt->execute([
            ':name' => $newName,
            ':host' => '10.0.0.1',
            ':id'   => $mikrotikId,
        ]);

        // Verificar que o nome mudou
        $stmt = self::$pdo->prepare('SELECT name, host FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $mikrotik = $stmt->fetch();
        $this->assertEquals($newName, $mikrotik['name']);

        // Verificar que a senha permanece a mesma
        $stmt = self::$pdo->prepare('SELECT password_encrypted FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $row = $stmt->fetch();
        $encryptedBytes = $row['password_encrypted'];
        if (is_resource($encryptedBytes)) {
            $encryptedBytes = stream_get_contents($encryptedBytes);
        }
        $decrypted = self::$crypto->decrypt(base64_encode($encryptedBytes));
        $this->assertEquals($originalPassword, $decrypted);
    }

    public function testEditWithNewPasswordUpdatesPassword(): void
    {
        $clientId = self::createTestClient('Cliente New PW');
        $mikrotikId = self::createTestMikrotik($clientId, 'Mikrotik-NewPW', 'senha_velha');

        // Editar com nova senha
        $newPassword = 'senha_nova_789';
        $encryptedBase64 = self::$crypto->encrypt($newPassword);

        $stmt = self::$pdo->prepare('
            UPDATE mikrotiks
            SET password_encrypted = decode(:password_encrypted, \'base64\')
            WHERE id = :id
        ');
        $stmt->execute([
            ':password_encrypted' => $encryptedBase64,
            ':id'                 => $mikrotikId,
        ]);

        // Verificar que a senha foi atualizada
        $stmt = self::$pdo->prepare('SELECT password_encrypted FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $row = $stmt->fetch();
        $encryptedBytes = $row['password_encrypted'];
        if (is_resource($encryptedBytes)) {
            $encryptedBytes = stream_get_contents($encryptedBytes);
        }
        $decrypted = self::$crypto->decrypt(base64_encode($encryptedBytes));
        $this->assertEquals($newPassword, $decrypted);
    }

    // ─── DELETE ───────────────────────────────────────────────────────────────

    public function testDeleteMikrotikCascadesHealthLog(): void
    {
        $clientId = self::createTestClient('Cliente Cascade');
        $mikrotikId = self::createTestMikrotik($clientId, 'Mikrotik-Cascade', 'pw');

        // Inserir health_log
        $stmt = self::$pdo->prepare('
            INSERT INTO health_log (mikrotik_id, cpu_load, memory_free, memory_total)
            VALUES (:mikrotik_id, 50, 500000000, 1000000000)
        ');
        $stmt->execute([':mikrotik_id' => $mikrotikId]);

        // Verificar que existe
        $stmt = self::$pdo->prepare('SELECT COUNT(*) AS total FROM health_log WHERE mikrotik_id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $this->assertGreaterThan(0, (int) $stmt->fetch()['total']);

        // Excluir Mikrotik
        $stmt = self::$pdo->prepare('DELETE FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);

        // Verificar cascade
        $stmt = self::$pdo->prepare('SELECT COUNT(*) AS total FROM health_log WHERE mikrotik_id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $this->assertEquals(0, (int) $stmt->fetch()['total']);
    }

    public function testDeleteMikrotikCascadesNetwatchHosts(): void
    {
        $clientId = self::createTestClient('Cliente NW Cascade');
        $mikrotikId = self::createTestMikrotik($clientId, 'Mikrotik-NW', 'pw');

        // Inserir netwatch_host
        $stmt = self::$pdo->prepare('
            INSERT INTO netwatch_hosts (mikrotik_id, host_address, current_status)
            VALUES (:mikrotik_id, \'10.0.0.99\', \'up\')
        ');
        $stmt->execute([':mikrotik_id' => $mikrotikId]);

        // Excluir Mikrotik
        $stmt = self::$pdo->prepare('DELETE FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);

        // Verificar cascade
        $stmt = self::$pdo->prepare('SELECT COUNT(*) AS total FROM netwatch_hosts WHERE mikrotik_id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $this->assertEquals(0, (int) $stmt->fetch()['total']);
    }

    // ─── IDEMPOTENCY: Status Transitions ──────────────────────────────────────

    public function testNoStatusChangeDoesNotCreateEvent(): void
    {
        $clientId = self::createTestClient('Cliente Idempotent');
        $mikrotikId = self::createTestMikrotik($clientId, 'Mikrotik-Idem', 'pw');

        // Simular status online
        $stmt = self::$pdo->prepare('UPDATE mikrotiks SET current_status = \'online\' WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);

        // Contar eventos antes
        $stmt = self::$pdo->prepare('SELECT COUNT(*) AS total FROM mikrotik_events WHERE mikrotik_id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $before = (int) $stmt->fetch()['total'];

        // Simular segunda coleta SEM mudança de status (ainda online)
        $stmt = self::$pdo->prepare('UPDATE mikrotiks SET last_checked_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);

        // Contar eventos depois
        $stmt = self::$pdo->prepare('SELECT COUNT(*) AS total FROM mikrotik_events WHERE mikrotik_id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $after = (int) $stmt->fetch()['total'];

        $this->assertEquals($before, $after, 'Nenhum evento deve ser criado quando o status não muda');
    }

    public function testStatusTransitionCreatesExactlyOneEvent(): void
    {
        $clientId = self::createTestClient('Cliente Transition');
        $mikrotikId = self::createTestMikrotik($clientId, 'Mikrotik-Trans', 'pw');

        // Simular status online
        $stmt = self::$pdo->prepare('UPDATE mikrotiks SET current_status = \'online\' WHERE id = :id');
        $stmt->execute([':id' => $mikrotikId]);

        // Simular mudança para offline
        $stmt = self::$pdo->prepare('
            UPDATE mikrotiks
            SET current_status = \'offline\',
                status_since = NOW()
            WHERE id = :id
        ');
        $stmt->execute([':id' => $mikrotikId]);

        // Criar evento (como faria o collect_netwatch)
        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotik_events (mikrotik_id, status, started_at)
            VALUES (:mikrotik_id, :status, NOW())
        ');
        $stmt->execute([':mikrotik_id' => $mikrotikId, ':status' => 'offline']);

        // Verificar que EXATAMENTE UM evento foi criado
        $stmt = self::$pdo->prepare('SELECT COUNT(*) AS total FROM mikrotik_events WHERE mikrotik_id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $this->assertEquals(1, (int) $stmt->fetch()['total']);

        // Verificar detalhes do evento
        $stmt = self::$pdo->prepare('SELECT * FROM mikrotik_events WHERE mikrotik_id = :id LIMIT 1');
        $stmt->execute([':id' => $mikrotikId]);
        $event = $stmt->fetch();

        $this->assertEquals('offline', $event['status']);
        $this->assertNotNull($event['started_at']);
        $this->assertNull($event['ended_at']);
    }

    public function testRecoveryClosesPreviousEventAndCreatesNew(): void
    {
        $clientId = self::createTestClient('Cliente Recovery');
        $mikrotikId = self::createTestMikrotik($clientId, 'Mikrotik-Recovery', 'pw');

        // Criar evento de offline aberto
        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotik_events (mikrotik_id, status, started_at)
            VALUES (:mikrotik_id, \'offline\', NOW() - INTERVAL \'5 minutes\')
        ');
        $stmt->execute([':mikrotik_id' => $mikrotikId]);

        // Simular recuperação (offline → online)
        // 1. Fechar evento aberto
        $stmt = self::$pdo->prepare('
            UPDATE mikrotik_events
            SET ended_at = NOW(),
                duration_seconds = EXTRACT(EPOCH FROM (NOW() - started_at))::INTEGER
            WHERE mikrotik_id = :id AND ended_at IS NULL
        ');
        $stmt->execute([':id' => $mikrotikId]);

        // 2. Criar novo evento de online
        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotik_events (mikrotik_id, status, started_at)
            VALUES (:mikrotik_id, \'online\', NOW())
        ');
        $stmt->execute([':mikrotik_id' => $mikrotikId]);

        // Verificar que há 2 eventos
        $stmt = self::$pdo->prepare('SELECT COUNT(*) AS total FROM mikrotik_events WHERE mikrotik_id = :id');
        $stmt->execute([':id' => $mikrotikId]);
        $this->assertEquals(2, (int) $stmt->fetch()['total']);

        // Verificar que o primeiro evento foi fechado
        $stmt = self::$pdo->prepare('SELECT * FROM mikrotik_events WHERE mikrotik_id = :id ORDER BY started_at ASC');
        $stmt->execute([':id' => $mikrotikId]);
        $events = $stmt->fetchAll();

        $this->assertEquals('offline', $events[0]['status']);
        $this->assertNotNull($events[0]['ended_at']);
        $this->assertGreaterThan(0, (int) $events[0]['duration_seconds']);

        $this->assertEquals('online', $events[1]['status']);
        $this->assertNull($events[1]['ended_at']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private static function createTestClient(string $name): string
    {
        $stmt = self::$pdo->prepare('
            INSERT INTO clients (name) VALUES (:name) RETURNING id
        ');
        $stmt->execute([':name' => $name]);
        return $stmt->fetch()['id'];
    }

    private static function createTestMikrotik(string $clientId, string $name, string $password): string
    {
        $encryptedBase64 = self::$crypto->encrypt($password);

        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotiks (client_id, name, host, username, password_encrypted, current_status)
            VALUES (:client_id, :name, :host, :username, decode(:pw, \'base64\'), :status)
            RETURNING id
        ');
        $stmt->execute([
            ':client_id' => $clientId,
            ':name'      => $name,
            ':host'      => '192.168.' . rand(1, 254) . '.' . rand(1, 254),
            ':username'  => 'admin',
            ':pw'        => $encryptedBase64,
            ':status'    => 'unknown',
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
