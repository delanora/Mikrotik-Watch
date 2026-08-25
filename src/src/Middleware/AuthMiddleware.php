<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Mikrotik Watch - Auth Middleware
 *
 * Middleware de autenticação. Verifica se o usuário está logado.
 * TODO: Implementar verificação de sessão.
 */
class AuthMiddleware
{
    /**
     * Verifica se o usuário está autenticado.
     *
     * @return bool
     */
    public static function check(): bool
    {
        // TODO: Implementar verificação de sessão/token
        // Por enquanto, permite acesso (para desenvolvimento)
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
}
