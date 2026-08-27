<?php

declare(strict_types=1);

/**
 * Mikrotik Watch - Front Controller
 *
 * Todo request HTTP entra por este arquivo.
 * Responsável por carregar configurações, rotas e despachar a requisição.
 */

// ─── Bootstrap ───────────────────────────────────────────────────────────────

// Carregar autoloader do Composer
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Carregar configurações
$config = require __DIR__ . '/config/config.php';

// Configurar timezone
date_default_timezone_set($config['app']['timezone']);

// ─── Exceções em modo debug ──────────────────────────────────────────────────
if ($config['app']['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// ─── Rotas protegidas por auth ──────────────────────────────────────────────
$publicRoutes = [
    '/login',
];

// ─── Request Atual ───────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim($uri, '/') ?: '/';

// ─── Verificar autenticação ──────────────────────────────────────────────────
\App\Middleware\AuthMiddleware::setTimeout($config['auth']['session_timeout'] ?? 3600);

if (!in_array($uri, $publicRoutes, true)) {
    // Verificar se é um asset público
    $isAsset = str_starts_with($uri, '/assets/') || str_ends_with($uri, '.css') || str_ends_with($uri, '.js');

    if (!$isAsset) {
        \App\Middleware\AuthMiddleware::requireAuth();

        // Verificar CSRF em requisições POST
        if ($method === 'POST' && !\App\Middleware\CsrfMiddleware::verify()) {
            http_response_code(403);
            echo 'Token CSRF inválido. Recarregue a página e tente novamente.';
            exit;
        }
    }
}

// ─── Rotas ───────────────────────────────────────────────────────────────────
$routes = require __DIR__ . '/config/routes.php';

// ─── Resolver Rota ───────────────────────────────────────────────────────────
$router = new \App\Router();
$router->loadRoutes($routes);

try {
    $handler = $router->resolve($method, $uri);

    if ($handler === null) {
        http_response_code(404);
        require __DIR__ . '/views/errors/404.php';
        exit;
    }

    // Despachar para o controller
    [$controllerName, $action] = explode('@', $handler);

    $controllerFile = __DIR__ . '/src/Controller/' . $controllerName . '.php';

    if (!file_exists($controllerFile)) {
        http_response_code(500);
        echo "Controller não encontrado: {$controllerName}";
        exit;
    }

    require_once $controllerFile;

    if (!class_exists("App\\Controller\\{$controllerName}")) {
        http_response_code(500);
        echo "Classe do controller não encontrada: {$controllerName}";
        exit;
    }

    $controller = new ("App\\Controller\\{$controllerName}")($config);
    $controller->$action();

} catch (\Throwable $e) {
    http_response_code(500);
    if ($config['app']['debug']) {
        echo "<h1>Erro 500</h1>";
        echo "<pre>" . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . "</pre>";
    } else {
        echo "Erro interno do servidor.";
    }
}
