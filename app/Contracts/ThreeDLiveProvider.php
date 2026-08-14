<?php

namespace App\Contracts;

use App\Exceptions\ThreeDProviderException;
use App\Support\ThreeD\ThreeDDraw;

/**
 * A swappable source of the current 3D draw.
 *
 * Display only. Nothing behind this contract settles a bet — 3D settlement runs
 * off the admin-entered ThreeDResult records, and deliberately so.
 */
interface ThreeDLiveProvider
{
    /**
     * Fetch the latest published 3D draw, or null when the upstream has none.
     *
     * @throws ThreeDProviderException on transport failure, a non-2xx response,
     *                                 a payload that is not a JSON object, or an
     *                                 exhausted daily call budget.
     */
    public function fetch(): ?ThreeDDraw;
}
