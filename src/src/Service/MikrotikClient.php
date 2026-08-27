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
            'Authorization' => 'Basic ' . base64_encode("{$this->username}:{$this->password}"),
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

    // ─── Batch (paralelo) ─────────────────────────────────────────────────────

    /**
     * Executa múltiplas requisições GET em paralelo usando curl_multi.
     * Todas as requisições são disparadas antes de qualquer resposta ser aguardada.
     *
     * @param list<array{mikrotik_id: string, endpoint: string}> $requests
     * @param int $maxConcurrency Limite máximo de requisições simultâneas
     * @return array<string, array{data?: array, error?: string}> Resultados indexados por mikrotik_id
     */
    public static function batchGet(
        array $requests,
        int $timeout = 5,
        bool $verifySsl = true,
        ?string $caCertPath = null,
        int $maxConcurrency = 20
    ): array {
        return self::batchRequest('GET', $requests, $timeout, $verifySsl, $caCertPath, $maxConcurrency);
    }

    /**
     * Executa múltiplas requisições HTTP em paralelo usando curl_multi.
     * Dispara todas as requisições simultaneamente e coleta os resultados depois.
     *
     * @param string $method Método HTTP (tipicamente 'GET')
     * @param list<array{mikrotik_id: string, endpoint: string, host: string, port: int, use_ssl: bool, username: string, password: string}> $requests
     * @return array<string, array{data?: array, error?: string}> Resultados indexados por mikrotik_id
     */
    public static function batchRequest(
        string $method,
        array $requests,
        int $timeout = 5,
        bool $verifySsl = true,
        ?string $caCertPath = null,
        int $maxConcurrency = 20
    ): array {
        if (empty($requests)) {
            return [];
        }

        // Limitar concorrência
        $batches = array_chunk($requests, $maxConcurrency);
        $allResults = [];

        foreach ($batches as $batch) {
            $mh = curl_multi_init();
            $handles = []; // resultKey => curl handle
            $results = [];

            // ─── Disparar TODAS as requisições do batch ────────────────────────
            foreach ($batch as $req) {
                $resultKey = $req['key'] ?? $req['mikrotik_id'] . '_' . md5($req['endpoint']);
                $scheme = $req['use_ssl'] ? 'https' : 'http';
                $url = "{$scheme}://{$req['host']}:{$req['port']}{$req['endpoint']}";

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_HEADER         => true,
                    CURLOPT_CUSTOMREQUEST  => strtoupper($method),
                    CURLOPT_SSL_VERIFYPEER => $verifySsl,
                    CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Authorization: Basic ' . base64_encode("{$req['username']}:{$req['password']}"),
                    ],
                ]);

                if ($caCertPath !== null && file_exists($caCertPath)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $caCertPath);
                }

                curl_multi_add_handle($mh, $ch);
                $handles[$resultKey] = $ch;
                $results[$resultKey] = null;
            }

            // ─── Executar todas em paralelo ─────────────────────────────────────
            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh, 1);
                }
            } while ($active && $status === CURLM_OK);

            // ─── Coletar resultados ────────────────────────────────────────────
            foreach ($handles as $resultKey => $ch) {
                $error = curl_error($ch);
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $rawResponse = curl_multi_getcontent($ch);

                if ($error !== '') {
                    $results[$resultKey] = ['error' => "cURL error: {$error}"];
                } elseif ($statusCode < 200 || $statusCode >= 300) {
                    $body = $headerSize > 0 ? substr($rawResponse, $headerSize) : $rawResponse;
                    $detail = '';
                    $decoded = json_decode($body, true);
                    if (is_array($decoded) && isset($decoded['detail'])) {
                        $detail = ": {$decoded['detail']}";
                    }
                    $results[$resultKey] = ['error' => "Erro HTTP {$statusCode}{$detail}"];
                } else {
                    $body = $headerSize > 0 ? substr($rawResponse, $headerSize) : $rawResponse;
                    if ($body === '' || $body === '[]') {
                        $results[$resultKey] = ['data' => []];
                    } else {
                        $decoded = json_decode($body, true);
                        $results[$resultKey] = ['data' => is_array($decoded) ? $decoded : []];
                    }
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            curl_multi_close($mh);
            $allResults = array_merge($allResults, $results);
        }

        return $allResults;
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
