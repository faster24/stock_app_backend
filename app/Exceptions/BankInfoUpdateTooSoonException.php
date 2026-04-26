<?php

namespace App\Exceptions;

use Carbon\Carbon;
use RuntimeException;

class BankInfoUpdateTooSoonException extends RuntimeException
{
    public function __construct(private readonly Carbon $nextAllowedAt)
    {
        parent::__construct('Bank info can only be updated once every 30 days.');
    }

    public function getNextAllowedAt(): Carbon
    {
        return $this->nextAllowedAt;
    }
}
