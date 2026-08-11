<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by a 3D history provider when it cannot produce a usable list:
 * a transport failure (upstream status is null), a non-2xx response, a payload
 * that is not a JSON object, or an exhausted daily call budget.
 */
class ThreeDProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $upstreamStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function upstreamStatus(): ?int
    {
        return $this->upstreamStatus;
    }
}
