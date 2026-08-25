<?php

declare(strict_types=1);

namespace App\Service\Http;

/**
 * Mikrotik Watch - HTTP Transport Interface
 *
 * Abstrai o transporte HTTP para permitir mock em testes.
 * Implementações reais usam cURL; em testes, MockTransport retorna respostas controladas.
 */
interface HttpTransport
{
    /**
     * Executa uma requisição HTTP.
     *
     * @param string $method   GET, POST, PUT, PATCH, DELETE
     * @param string $url      URL completa da requisição
     * @param array  $headers  Headers HTTP (chave => valor)
     * @param ?string $body    Corpo da requisição (para POST/PUT/PATCH)
     * @return array ['status' => int, 'body' => string, 'headers' => array]
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null
    ): array;
}
