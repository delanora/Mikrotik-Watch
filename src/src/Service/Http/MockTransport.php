<?php

declare(strict_types=1);

namespace App\Service\Http;

/**
 * Mikrotik Watch - Mock HTTP Transport
 *
 * Transporte mock para testes unitários. Registra chamadas e retorna respostas pré-configuradas.
 * Não depende de nenhum Mikrotik real.
 */
class MockTransport implements HttpTransport
{
    /** @var array<int, array{status: int, body: string, headers: array}> */
    private array $responses = [];

    /** @var list<array{method: string, url: string, headers: array, body: ?string}> */
    private array $calls = [];

    private int $callIndex = 0;

    /**
     * Registra uma resposta para a próxima chamada.
     */
    public function mockResponse(int $status, string $body, array $headers = []): void
    {
        $this->responses[] = [
            'status'  => $status,
            'body'    => $body,
            'headers' => $headers,
        ];
    }

    /**
     * Registra múltiplas respostas de uma vez.
     *
     * @param list<array{status: int, body: string, headers?: array}> $responses
     */
    public function mockResponses(array $responses): void
    {
        foreach ($responses as $response) {
            $this->mockResponse(
                $response['status'],
                $response['body'],
                $response['headers'] ?? []
            );
        }
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null
    ): array {
        $this->calls[] = [
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $body,
        ];

        if ($this->callIndex < count($this->responses)) {
            $response = $this->responses[$this->callIndex];
            $this->callIndex++;
            return $response;
        }

        return [
            'status'  => 200,
            'body'    => '{}',
            'headers' => [],
        ];
    }

    /**
     * Retorna todas as chamadas registradas.
     *
     * @return list<array{method: string, url: string, headers: array, body: ?string}>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    /**
     * Retorna a última chamada registrada.
     */
    public function getLastCall(): ?array
    {
        return $this->calls !== [] ? end($this->calls) : null;
    }

    /**
     * Reseta o estado do mock.
     */
    public function reset(): void
    {
        $this->responses = [];
        $this->calls = [];
        $this->callIndex = 0;
    }
}
