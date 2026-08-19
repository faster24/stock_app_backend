<?php

namespace App\Exceptions;

use RuntimeException;

class TooManySecurityPinAttemptsException extends RuntimeException
{
    public function __construct(private readonly int $retryAfter)
    {
        parent::__construct('Too many incorrect security PIN attempts. Please try again later.');
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
