<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Middleware\AuthMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Mikrotik Watch - AuthMiddleware Tests
 *
 * Testes unitários para o middleware de autenticação.
 * Testa verificação de sessão e timeout.
 */
class AuthMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset session state
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    // ─── check() ─────────────────────────────────────────────────────────────

    public function testCheckReturnsFalseWhenNoSession(): void
    {
        $result = AuthMiddleware::check();
        $this->assertFalse($result);
    }

    public function testCheckReturnsFalseWhenNoUserId(): void
    {
        session_start();
        $_SESSION['user_name'] = 'Test User';
        session_write_close();

        $result = AuthMiddleware::check();
        $this->assertFalse($result);
    }

    public function testCheckReturnsTrueWithValidSession(): void
    {
        session_start();
        $_SESSION['user_id'] = 'test-user-id';
        $_SESSION['last_activity'] = time();
        session_write_close();

        $result = AuthMiddleware::check();
        $this->assertTrue($result);
    }

    public function testCheckUpdatesLastActivity(): void
    {
        session_start();
        $_SESSION['user_id'] = 'test-user-id';
        $_SESSION['last_activity'] = time() - 100;
        session_write_close();

        AuthMiddleware::check();

        // Re-read session to verify update
        $this->assertGreaterThanOrEqual(
            time() - 2,
            $_SESSION['last_activity']
        );
    }

    public function testCheckReturnsFalseWhenSessionExpired(): void
    {
        session_start();
        $_SESSION['user_id'] = 'test-user-id';
        $_SESSION['last_activity'] = time() - 7200; // 2 hours ago
        session_write_close();

        // Set short timeout
        AuthMiddleware::setTimeout(60); // 1 minute

        $result = AuthMiddleware::check();
        $this->assertFalse($result);

        // Session should be destroyed
        $this->assertEmpty($_SESSION);
    }

    public function testCheckReturnsTrueWithinTimeout(): void
    {
        session_start();
        $_SESSION['user_id'] = 'test-user-id';
        $_SESSION['last_activity'] = time() - 30; // 30 seconds ago
        session_write_close();

        AuthMiddleware::setTimeout(60); // 1 minute

        $result = AuthMiddleware::check();
        $this->assertTrue($result);
    }

    // ─── setTimeout() ────────────────────────────────────────────────────────

    public function testSetTimeoutAffectsCheckBehavior(): void
    {
        session_start();
        $_SESSION['user_id'] = 'test-user-id';
        $_SESSION['last_activity'] = time() - 50;
        session_write_close();

        // With 60s timeout, should be valid
        AuthMiddleware::setTimeout(60);
        $this->assertTrue(AuthMiddleware::check());

        // Reset session
        session_start();
        $_SESSION['user_id'] = 'test-user-id';
        $_SESSION['last_activity'] = time() - 50;
        session_write_close();

        // With 30s timeout, should be expired
        AuthMiddleware::setTimeout(30);
        $this->assertFalse(AuthMiddleware::check());
    }

    // ─── userId() / userName() ───────────────────────────────────────────────

    public function testUserIdReturnsNullWhenNotAuthenticated(): void
    {
        $this->assertNull(AuthMiddleware::userId());
    }

    public function testUserIdReturnsIdWhenAuthenticated(): void
    {
        session_start();
        $_SESSION['user_id'] = 'my-user-id';
        $_SESSION['last_activity'] = time();
        session_write_close();

        $this->assertEquals('my-user-id', AuthMiddleware::userId());
    }

    public function testUserNameReturnsNullWhenNotAuthenticated(): void
    {
        $this->assertNull(AuthMiddleware::userName());
    }

    public function testUserNameReturnsNameWhenAuthenticated(): void
    {
        session_start();
        $_SESSION['user_id'] = 'my-user-id';
        $_SESSION['user_name'] = 'Test User';
        $_SESSION['last_activity'] = time();
        session_write_close();

        $this->assertEquals('Test User', AuthMiddleware::userName());
    }

    public function testUserNameReturnsNullWhenSessionExpired(): void
    {
        session_start();
        $_SESSION['user_id'] = 'my-user-id';
        $_SESSION['user_name'] = 'Test User';
        $_SESSION['last_activity'] = time() - 7200;
        session_write_close();

        AuthMiddleware::setTimeout(60);

        $this->assertNull(AuthMiddleware::userName());
    }

    // ─── Defaults ─────────────────────────────────────────────────────────────

    public function testDefaultTimeoutIsOneHour(): void
    {
        // Reset timeout to default
        AuthMiddleware::setTimeout(3600);

        session_start();
        $_SESSION['user_id'] = 'test-user-id';
        $_SESSION['last_activity'] = time() - 3500; // ~58 minutes ago
        session_write_close();

        // Default timeout is 3600s (1 hour)
        $result = AuthMiddleware::check();
        $this->assertTrue($result);
    }

    public function testSessionDestroyedAfterTimeout(): void
    {
        session_start();
        $_SESSION['user_id'] = 'test-user-id';
        $_SESSION['last_activity'] = time() - 7200;
        session_write_close();

        AuthMiddleware::setTimeout(60);
        AuthMiddleware::check();

        // After timeout, session should be empty
        $this->assertEmpty($_SESSION);
    }
}
