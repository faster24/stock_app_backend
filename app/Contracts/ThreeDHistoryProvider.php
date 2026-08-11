<?php

namespace App\Contracts;

use App\Exceptions\ThreeDProviderException;
use App\Support\ThreeD\ThreeDHistoryEntry;

/**
 * A swappable source of past 3D draw results.
 *
 * Display only. Nothing behind this contract settles a bet — 3D settlement runs
 * off the admin-entered ThreeDResult records, and deliberately so.
 */
interface ThreeDHistoryProvider
{
    /**
     * Fetch recent 3D draws, most recent first.
     *
     * @return list<ThreeDHistoryEntry>
     *
     * @throws ThreeDProviderException on transport failure, a non-2xx response,
     *                                 a payload that is not a JSON object, or an
     *                                 exhausted daily call budget.
     */
    public function fetch(): array;
}
