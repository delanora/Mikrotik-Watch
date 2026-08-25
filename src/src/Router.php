<?php

declare(strict_types=1);

namespace App;

/**
 * Mikrotik Watch - Router Simples
 *
 * Roteador leve para mapear URIs para controllers/ações.
 * Suporta parâmetros dinâmicos na URL (ex.: /mikrotiks/{id}).
 */
class Router
{
    /**
     * Lista de rotas registradas.
     * Cada rota: ['method' => string, 'pattern' => string, 'handler' => string]
     */
    private array $routes = [];

    /**
     * Carrega um array de rotas no formato [METHOD, pattern, handler].
     *
     * @param array $routes
     * @return void
     */
    public function loadRoutes(array $routes): void
    {
        foreach ($routes as [$method, $pattern, $handler]) {
            $this->addRoute($method, $pattern, $handler);
        }
    }

    /**
     * Registra uma rota.
     *
     * @param string $method  HTTP method (GET, POST, PUT, DELETE)
     * @param string $pattern Pattern da URI (ex.: /mikrotiks/{id})
     * @param string $handler Controller@action
     * @return void
     */
    public function addRoute(string $method, string $pattern, string $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    /**
     * Resolve a requisição e retorna o handler correspondente.
     *
     * @param string $method HTTP method
     * @param string $uri    URI da requisição
     * @return string|null   Controller@action ou null se não encontrada
     */
    public function resolve(string $method, string $uri): ?string
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }

            $params = $this->matchPattern($route['pattern'], $uri);
            if ($params !== false) {
                return $route['handler'];
            }
        }

        return null;
    }

    /**
     * Verifica se a URI corresponde ao pattern e extrai parâmetros.
     *
     * @param string $pattern
     * @param string $uri
     * @return array|false  Array de parâmetros ou false se não corresponder
     */
    private function matchPattern(string $pattern, string $uri): array|false
    {
        // Converter {param} para grupos de captura regex
        $regexPattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regexPattern = '#^' . $regexPattern . '$#';

        if (preg_match($regexPattern, $uri, $matches)) {
            return array_filter($matches, fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY);
        }

        return false;
    }

    /**
     * Retorna todas as rotas registradas.
     *
     * @return array
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
