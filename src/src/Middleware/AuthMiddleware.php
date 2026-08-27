<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Mikrotik Watch - Auth Middleware
 *
 * Middleware de autenticação. Verifica se o usuário está logado
 * e se a sessão não expirou (timeout configurável).
 */
class AuthMiddleware
{
    private static int $timeout = 3600;

    /**
     * Configura o timeout da sessão.
     */
    public static function setTimeout(int $seconds): void
    {
        self::$timeout = $seconds;
    }

    /**
     * Verifica se o usuário está autenticado.
     *
     * @return bool
     */
    public static function check(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        // Verificar timeout de sessão
        $lastActivity = $_SESSION['last_activity'] ?? 0;
        if (time() - $lastActivity > self::$timeout) {
            self::destroy();
            return false;
        }

        // Atualizar atividade
        $_SESSION['last_activity'] = time();

        return true;
    }

    /**
     * Redireciona para a página de login se não autenticado.
     *
     * @return void
     */
    public static function requireAuth(): void
    {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }

    /**
     * Retorna o ID do usuário logado.
     */
    public static function userId(): ?string
    {
        if (!self::check()) {
            return null;
        }
        return $_SESSION['user_id'];
    }

    /**
     * Retorna o nome do usuário logado.
     */
    public static function userName(): ?string
    {
        if (!self::check()) {
            return null;
        }
        return $_SESSION['user_name'] ?? null;
    }

    /**
     * Retorna o papel do usuário logado.
     */
    public static function userRole(): ?string
    {
        if (!self::check()) {
            return null;
        }
        return $_SESSION['user_role'] ?? 'viewer';
    }

    /**
     * Verifica se o usuário é admin.
     */
    public static function isAdmin(): bool
    {
        return self::userRole() === 'admin';
    }

    /**
     * Exige que o usuário seja admin. Redireciona para dashboard com mensagem de erro se não for.
     */
    public static function requireAdmin(): void
    {
        self::requireAuth();

        if (!self::isAdmin()) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['flash_error'] = 'Você não tem permissão para executar esta ação.';
            header('Location: /dashboard?error=forbidden');
            exit;
        }
    }

    /**
     * Destrói a sessão atual.
     */
    private static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }
}
