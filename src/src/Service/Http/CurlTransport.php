<?php

declare(strict_types=1);

namespace App\Service\Http;

/**
 * Mikrotik Watch - cURL HTTP Transport
 *
 * Implementação real do HttpTransport usando a extensão PHP cURL.
 */
class CurlTransport implements HttpTransport
{
    private int $timeout;
    private bool $verifySsl;
    private ?string $caCertPath;

    public function __construct(
        int $timeout = 5,
        bool $verifySsl = true,
        ?string $caCertPath = null
    ) {
        $this->timeout = $timeout;
        $this->verifySsl = $verifySsl;
        $this->caCertPath = $caCertPath;
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null
    ): array {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_HEADER         => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        if ($this->caCertPath !== null && file_exists($this->caCertPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caCertPath);
        }

        if (!empty($headers)) {
            $headerLines = [];
            foreach ($headers as $key => $value) {
                $headerLines[] = "{$key}: {$value}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL error: {$error}");
        }

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $responseHeaders = [];
        if ($headerSize > 0) {
            $rawHeaders = substr($response, 0, $headerSize);
            $responseBody = substr($response, $headerSize);

            foreach (explode("\r\n", $rawHeaders) as $line) {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $responseHeaders[trim($key)] = trim($value);
                }
            }
        } else {
            $responseBody = $response;
        }

        return [
            'status'  => $statusCode,
            'body'    => $responseBody,
            'headers' => $responseHeaders,
        ];
    }
}
