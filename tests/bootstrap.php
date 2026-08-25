<?php

declare(strict_types=1);

/**
 * Mikrotik Watch - PHPUnit Bootstrap
 *
 * Carrega autoloader e configurações para testes.
 */

// Carregar autoloader do Composer
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Configurar ambiente de teste
$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_DEBUG'] = 'true';

// Funções helper para testes
function testConfig(): array
{
    return [
        'app' => [
            'name'     => 'Mikrotik Watch',
            'env'      => 'testing',
            'debug'    => true,
            'url'      => 'http://localhost:8080',
            'port'     => 8080,
            'secret'   => 'test-secret-key',
            'timezone' => 'America/Sao_Paulo',
        ],
        'database' => [
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => (int) (getenv('TEST_DB_PORT') ?: '5432'),
            'name'     => getenv('TEST_DB_NAME') ?: 'mikrotik_watch_test',
            'user'     => getenv('TEST_DB_USER') ?: 'mikrotik_watch',
            'password' => getenv('TEST_DB_PASSWORD') ?: '',
        ],
        'mikrotik' => [
            'default_port'      => 443,
            'default_use_ssl'   => true,
            'default_verify_ssl' => true,
            'default_user'      => 'admin',
            'default_timeout'   => 5,
            'allow_self_signed' => false,
            'credential_key'    => \App\Service\CredentialCrypto::generateKey(),
        ],
        'auth' => [
            'session_timeout' => 3600,
        ],
        'collect' => [
            'interval' => 5,
        ],
    ];
}
