<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\MikrotikApiException;
use App\Service\Http\CurlTransport;
use App\Service\Http\HttpTransport;

/**
 * Mikrotik Watch - Mikrotik REST API Client
 *
 * Comunicação com equipamentos Mikrotik RouterOS via API REST (porta 80/443).
 * A porta 8728 (API binária) NUNCA é utilizada.
 *
 * Suporta métodos GET, POST, PUT, PATCH, DELETE via HTTP Basic Auth.
 * O transporte HTTP é injetável (HttpTransport) para permitir mock em testes.
 */
class MikrotikClient
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private int $timeout;
    private bool $useSsl;
    private bool $verifySsl;
    private ?string $caCertPath;
    private HttpTransport $transport;

    public function __construct(
        string $host,
        string $username = 'admin',
        string $password = '',
        int $port = 443,
        bool $useSsl = true,
        bool $verifySsl = true,
        ?string $caCertPath = null,
        int $timeout = 5,
        ?HttpTransport $transport = null
    ) {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->port = $port;
        $this->useSsl = $useSsl;
        $this->verifySsl = $verifySsl;
        $this->caCertPath = $caCertPath;
        $this->timeout = $timeout;
        $this->transport = $transport ?? new CurlTransport($timeout, $verifySsl, $caCertPath);
    }

    // ─── Métodos HTTP genéricos ───────────────────────────────────────────────

    /**
     * Executa uma requisição GET.
     *
     * @param string $endpoint Caminho do endpoint REST (ex.: /rest/system/resource)
     * @return array Dados retornados pela API
     * @throws MikrotikApiException
     */
    public function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint);
    }

    /**
     * Executa uma requisição POST.
     *
     * @param string $endpoint Caminho do endpoint REST
     * @param array  $data     Dados a enviar no corpo
     * @return array Dados retornados pela API
     * @throws MikrotikApiException
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, $data);
    }

    /**
     * Executa uma requisição PUT.
     *
     * @param string $endpoint Caminho do endpoint REST
     * @param array  $data     Dados a enviar no corpo
     * @return array Dados retornados pela API
     * @throws MikrotikApiException
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $endpoint, $data);
    }

    /**
     * Executa uma requisição PATCH.
     *
     * @param string $endpoint Caminho do endpoint REST
     * @param array  $data     Dados a enviar no corpo
     * @return array Dados retornados pela API
     * @throws MikrotikApiException
     */
    public function patch(string $endpoint, array $data = []): array
    {
        return $this->request('PATCH', $endpoint, $data);
    }

    /**
     * Executa uma requisição DELETE.
     *
     * @param string $endpoint Caminho do endpoint REST
     * @return array Dados retornados pela API
     * @throws MikrotikApiException
     */
    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }

    // ─── Métodos de conveniência ──────────────────────────────────────────────

    /**
     * Obtém informações do system resource (CPU, memória, uptime, etc.).
     *
     * @return array
     * @throws MikrotikApiException
     */
    public function systemResource(): array
    {
        return $this->get('/rest/system/resource');
    }

    /**
     * Obtém dados de system health (temperatura, voltagem, fan speed).
     *
     * @return array
     * @throws MikrotikApiException
     */
    public function systemHealth(): array
    {
        return $this->get('/rest/system/health');
    }

    /**
     * Obtém a lista de hosts do Netwatch.
     *
     * @return array
     * @throws MikrotikApiException
     */
    public function netwatch(): array
    {
        return $this->get('/rest/tool/netwatch');
    }

    /**
     * Obtém a lista de interfaces.
     *
     * @return array
     * @throws MikrotikApiException
     */
    public function interfaces(): array
    {
        return $this->get('/rest/interface');
    }

    /**
     * Obtém informações do sistema (identity, uptime, etc.).
     *
     * @return array
     * @throws MikrotikApiException
     */
    public function systemInfo(): array
    {
        return $this->get('/rest/system');
    }

    // ─── Método interno ───────────────────────────────────────────────────────

    /**
     * Executa uma requisição HTTP à API REST do Mikrotik.
     *
     * @param string $method   Método HTTP
     * @param string $endpoint Endpoint REST
     * @param array  $data     Dados para corpo (POST/PUT/PATCH)
     * @return array Dados parseados da resposta
     * @throws MikrotikApiException
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $scheme = $this->useSsl ? 'https' : 'http';
        $url = "{$scheme}://{$this->host}:{$this->port}{$endpoint}";

        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];

        $body = !empty($data) ? json_encode($data, JSON_THROW_ON_ERROR) : null;

        try {
            $response = $this->transport->request($method, $url, $headers, $body);
        } catch (\Throwable $e) {
            throw new MikrotikApiException(
                "Falha de conexão com {$this->host}: {$e->getMessage()}",
                $this->host,
                $endpoint,
                0,
                $e
            );
        }

        $status = $response['status'];
        $responseBody = $response['body'];

        if ($status < 200 || $status >= 300) {
            $message = "Erro na API Mikrotik (HTTP {$status})";
            if ($responseBody !== '') {
                $decoded = json_decode($responseBody, true);
                if (is_array($decoded) && isset($decoded['detail'])) {
                    $message .= ": {$decoded['detail']}";
                } else {
                    $message .= ": {$responseBody}";
                }
            }

            throw new MikrotikApiException(
                $message,
                $this->host,
                $endpoint,
                $status
            );
        }

        if ($responseBody === '' || $responseBody === '[]') {
            return [];
        }

        $decoded = json_decode($responseBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }
}
