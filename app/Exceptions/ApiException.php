<?php

namespace App\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly ?array $data = null,
    ) {
        parent::__construct($message);
    }
}
