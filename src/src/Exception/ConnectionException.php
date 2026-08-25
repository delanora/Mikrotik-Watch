<?php

declare(strict_types=1);

namespace App\Exception;

class ConnectionException extends AppException
{
    public function __construct(string $host = '', string $message = '', ?\Throwable $previous = null)
    {
        $msg = $message ?: "Falha ao conectar com o equipamento em {$host}.";
        parent::__construct($msg, 503, $previous);
    }
}
