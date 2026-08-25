<?php

declare(strict_types=1);

namespace App\Exception;

class NotFoundException extends AppException
{
    public function __construct(string $entity = 'Recurso', string|int $identifier = '', ?\Throwable $previous = null)
    {
        $message = $identifier !== ''
            ? "{$entity} com identificador '{$identifier}' não encontrado(a)."
            : "{$entity} não encontrado(a).";

        parent::__construct($message, 404, $previous);
    }
}
