<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when a result correction would require reverting a completed
 * settlement run and the caller has not confirmed the revert.
 */
class SettlementRevertRequiredException extends DomainException
{
    public function __construct(public readonly string $historyId)
    {
        parent::__construct(
            'A completed settlement exists for this result. Confirm the revert to proceed.'
        );
    }
}
