<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Mikrotik Watch - CSRF Middleware
 *
 * Gera e valida tokens CSRF para requisições POST.
 * Token é armazenado na sessão e validado em cada requisição POST.
 */
class CsrfMiddleware
{
    private const TOKEN_KEY = '_csrf_token';
    private const TOKEN_LENGTH = 32;

    /**
     * Gera um token CSRF e armazena na sessão.
     */
    public static function generateToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $_SESSION[self::TOKEN_KEY] = $token;

        return $token;
    }

    /**
     * Obtém o token CSRF atual (gera um novo se não existir).
     */
    public static function getToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION[self::TOKEN_KEY])) {
            return self::generateToken();
        }

        return $_SESSION[self::TOKEN_KEY];
    }

    /**
     * Valida o token CSRF enviado.
     */
    public static function validateToken(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if ($token === null || $token === '') {
            return false;
        }

        $stored = $_SESSION[self::TOKEN_KEY] ?? '';

        if ($stored === '') {
            return false;
        }

        // Comparação segura contra timing attacks
        return hash_equals($stored, $token);
    }

    /**
     * Renderiza um campo hidden com o token CSRF.
     */
    public static function field(): string
    {
        $token = self::getToken();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Verifica se a requisição atual é protegida (POST sem ser login/assets).
     */
    public static function shouldProtect(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }

        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Rotas públicas (não protegidas)
        $publicPaths = ['/login', '/api/collect'];

        foreach ($publicPaths as $path) {
            if ($uri === $path || str_starts_with($uri, '/assets/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Executa a validação CSRF. Retorna true se válido, false se inválido.
     */
    public static function verify(): bool
    {
        if (!self::shouldProtect()) {
            return true;
        }

        $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        return self::validateToken($token);
    }
}
