<?php

declare(strict_types=1);

/**
 * Mikrotik Watch - Configurações
 *
 * Carrega variáveis de ambiente do arquivo .env
 * e disponibiliza como array global.
 */

// Carregar variáveis de ambiente do .env
function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        throw new RuntimeException("Arquivo .env não encontrado em: {$path}");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Ignorar comentários
        if (str_starts_with(trim($line), '#')) {
            continue;
        }

        // Extrair chave=valor
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }
}

// Carregar .env
$envPath = dirname(__DIR__, 2) . '/.env';
if (file_exists($envPath)) {
    loadEnv($envPath);
}

// Função helper para acessar configurações
function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// ─── Configurações da aplicação ──────────────────────────────────────────────
return [
    'app' => [
        'name'    => env('APP_NAME', 'Mikrotik Watch'),
        'env'     => env('APP_ENV', 'production'),
        'debug'   => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
        'url'     => env('APP_URL', 'http://localhost:8080'),
        'port'    => (int) env('APP_PORT', '8080'),
        'secret'  => env('APP_SECRET', ''),
        'timezone'=> env('APP_TIMEZONE', 'America/Sao_Paulo'),
    ],
    'database' => [
        'host'     => env('DB_HOST', '127.0.0.1'),
        'port'     => (int) env('DB_PORT', '5432'),
        'name'     => env('DB_NAME', 'mikrotik_watch_db'),
        'user'     => env('DB_USER', 'mikrotik_watch'),
        'password' => env('DB_PASSWORD', ''),
    ],
    'mikrotik' => [
        'default_port'     => (int) env('MIKROTIK_DEFAULT_PORT', '443'),
        'default_use_ssl'  => filter_var(env('MIKROTIK_DEFAULT_USE_SSL', 'true'), FILTER_VALIDATE_BOOLEAN),
        'default_verify_ssl' => filter_var(env('MIKROTIK_DEFAULT_VERIFY_SSL', 'true'), FILTER_VALIDATE_BOOLEAN),
        'default_user'     => env('MIKROTIK_DEFAULT_API_USER', 'admin'),
        'default_timeout'  => (int) env('MIKROTIK_DEFAULT_API_TIMEOUT', '5'),
        'allow_self_signed' => filter_var(env('MIKROTIK_ALLOW_SELF_SIGNED', 'false'), FILTER_VALIDATE_BOOLEAN),
        'credential_key'   => env('CREDENTIAL_ENCRYPTION_KEY', ''),
    ],
    'failure' => [
        'threshold' => (int) env('FAILURE_THRESHOLD', '3'),
    ],
    'auth' => [
        'session_timeout' => (int) env('SESSION_TIMEOUT', '3600'),
    ],
    'collect' => [
        'interval' => (int) env('COLLECT_INTERVAL', '5'),
    ],
];
