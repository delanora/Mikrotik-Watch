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
            'name'  => 'Mikrotik Watch',
            'env'   => 'testing',
            'debug' => true,
            'url'   => 'http://localhost:8080',
            'port'  => 8080,
            'secret' => 'test-secret-key',
            'timezone' => 'America/Sao_Paulo',
        ],
        'database' => [
            'host'     => '127.0.0.1',
            'port'     => 5432,
            'name'     => 'mikrotik_watch_test',
            'user'     => 'mikrotik_watch',
            'password' => '',
        ],
        'mikrotik' => [
            'default_port'    => 8728,
            'default_user'    => 'admin',
            'default_timeout' => 10,
        ],
        'collect' => [
            'interval' => 5,
        ],
    ];
}
