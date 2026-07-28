<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by a 2D result provider when it cannot produce a usable snapshot:
 * a transport failure (upstream status is null), a non-2xx response, or a
 * payload that is not a JSON object.
 */
class TwoDProviderException extends RuntimeException
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
