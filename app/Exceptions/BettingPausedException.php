<?php

namespace App\Exceptions;

use RuntimeException;

class BettingPausedException extends RuntimeException
{
    public function __construct(private readonly string $betType, string $message)
    {
        parent::__construct($message);
    }

    public function getBetType(): string
    {
        return $this->betType;
    }
}
