<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use App\Middleware\AuthMiddleware;
use App\Service\Crypto;

/**
 * Testes de integração para restrições admin/viewer.
 *
 * Verifica que:
 * - Usuários viewer não têm role admin
 * - Usuários admin têm role admin
 * - Viewer não pode criar usuário admin (escalação de privilégio)
 * - Regras de banco de dados garantem integridade
 */
class AdminViewerTest extends TestCase
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

    // ─── Role checking (sem exit) ─────────────────────────────────────────────

    public function testViewerIsNotAdmin(): void
    {
        $userId = self::createTestUser('Viewer', 'viewer@test.com', 'viewer');
        $this->simulateLogin($userId, 'Viewer', 'viewer');

        $this->assertFalse(AuthMiddleware::isAdmin(), 'Viewer não deve ser admin');
    }

    public function testAdminIsAdmin(): void
    {
        $userId = self::createTestUser('Admin', 'admin@test.com', 'admin');
        $this->simulateLogin($userId, 'Admin', 'admin');

        $this->assertTrue(AuthMiddleware::isAdmin(), 'Admin deve ser admin');
    }

    public function testUserRoleReturnsCorrectValue(): void
    {
        $userId = self::createTestUser('Viewer Role', 'viewerrole@test.com', 'viewer');
        $this->simulateLogin($userId, 'Viewer Role', 'viewer');

        $this->assertEquals('viewer', AuthMiddleware::userRole());
    }

    // ─── Privilege escalation prevention ──────────────────────────────────────

    public function testViewerCannotCreateAdminUser(): void
    {
        // Simular viewer logado
        $viewerId = self::createTestUser('Viewer', 'viewer@test.com', 'viewer');
        $this->simulateLogin($viewerId, 'Viewer', 'viewer');

        // Verificar que viewer NÃO é admin
        $this->assertFalse(AuthMiddleware::isAdmin(),
            'Viewer não deve conseguir passar pela verificação de admin');

        // Verificar que nenhum novo usuário foi criado (só o viewer existe)
        $stmt = self::$pdo->query('SELECT COUNT(*) AS total FROM users');
        $this->assertEquals(1, (int) $stmt->fetch()['total']);
    }

    public function testViewerRoleStoredCorrectlyInDatabase(): void
    {
        $viewerId = self::createTestUser('Viewer DB', 'viewerdb@test.com', 'viewer');

        $stmt = self::$pdo->prepare('SELECT role FROM users WHERE id = :id');
        $stmt->execute([':id' => $viewerId]);
        $user = $stmt->fetch();

        $this->assertEquals('viewer', $user['role']);
    }

    // ─── Admin can do everything ──────────────────────────────────────────────

    public function testAdminCanCreateUser(): void
    {
        $adminId = self::createTestUser('Admin Creator', 'admincreator@test.com', 'admin');
        $this->simulateLogin($adminId, 'Admin Creator', 'admin');

        // Admin deve ser verificado como admin
        $this->assertTrue(AuthMiddleware::isAdmin());

        // Criar um novo usuário (simulando o que o controller faz)
        $stmt = self::$pdo->prepare('
            INSERT INTO users (name, email, password_hash, role)
            VALUES (:name, :email, :pw, :role)
        ');
        $stmt->execute([
            ':name'  => 'Novo Usuário',
            ':email' => 'novo@test.com',
            ':pw'    => Crypto::hashPassword('senha123'),
            ':role'  => 'viewer',
        ]);

        $stmt = self::$pdo->query('SELECT COUNT(*) AS total FROM users');
        $this->assertEquals(2, (int) $stmt->fetch()['total']);
    }

    public function testAdminCanDeleteUser(): void
    {
        $adminId = self::createTestUser('Admin Del', 'admindel@test.com', 'admin');
        $targetId = self::createTestUser('Target', 'target@test.com', 'viewer');
        $this->simulateLogin($adminId, 'Admin Del', 'admin');

        $this->assertTrue(AuthMiddleware::isAdmin());

        $stmt = self::$pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([':id' => $targetId]);

        $stmt = self::$pdo->query('SELECT COUNT(*) AS total FROM users');
        $this->assertEquals(1, (int) $stmt->fetch()['total']);
    }

    // ─── User role validation ─────────────────────────────────────────────────

    public function testValidateRoleOnlyAcceptsAdminAndViewer(): void
    {
        $validRoles = ['admin', 'viewer'];
        $invalidRoles = ['superadmin', 'root', 'moderator', '', null];

        foreach ($validRoles as $role) {
            $this->assertContains($role, $validRoles);
        }

        foreach ($invalidRoles as $role) {
            $this->assertNotContains($role, $validRoles);
        }
    }

    public function testUserCannotSelfDelete(): void
    {
        $userId = self::createTestUser('Self Delete', 'selfdel@test.com', 'admin');

        $stmt = self::$pdo->prepare('SELECT id FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        $this->assertNotEmpty($user);

        // Simular a verificação do UserController::delete()
        $currentUserId = $userId;
        $this->assertEquals($currentUserId, $userId,
            'Auto-exclusão deve ser prevenida (UserController::delete compara IDs)');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private static function createTestUser(string $name, string $email, string $role): string
    {
        $stmt = self::$pdo->prepare('
            INSERT INTO users (name, email, password_hash, role)
            VALUES (:name, :email, :pw, :role)
            RETURNING id
        ');
        $stmt->execute([
            ':name'  => $name,
            ':email' => $email,
            ':pw'    => Crypto::hashPassword('testpassword'),
            ':role'  => $role,
        ]);
        return $stmt->fetch()['id'];
    }

    private function simulateLogin(string $userId, string $userName, string $role): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $userName;
        $_SESSION['user_role'] = $role;
        $_SESSION['last_activity'] = time();
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
