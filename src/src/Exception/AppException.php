<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Mikrotik Watch - Exceção Base
 *
 * Exceção base para todas as exceções customizadas da aplicação.
 */
class AppException extends \RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
