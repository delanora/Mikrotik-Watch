<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Mikrotik Watch - Mikrotik API Exception
 *
 * Exceção lançada quando uma chamada à API REST do Mikrotik RouterOS falha.
 * Inclui contexto do erro: host, endpoint e código HTTP.
 */
class MikrotikApiException extends AppException
{
    private string $host;
    private string $endpoint;
    private int $httpStatus;

    public function __construct(
        string $message,
        string $host = '',
        string $endpoint = '',
        int $httpStatus = 0,
        ?\Throwable $previous = null
    ) {
        $this->host = $host;
        $this->endpoint = $endpoint;
        $this->httpStatus = $httpStatus;

        parent::__construct($message, $httpStatus ?: 500, $previous);
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
