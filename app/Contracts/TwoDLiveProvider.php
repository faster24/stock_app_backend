<?php

namespace App\Contracts;

use App\Exceptions\TwoDProviderException;
use App\Support\TwoD\TwoDLiveSnapshot;

/**
 * A swappable source of 2D lottery results.
 *
 * Consumers depend only on this contract and the normalized snapshot it returns,
 * so the concrete upstream (thaistock2d, an alternate vendor, a manual feed) can
 * be switched via the `services.twod.driver` config without touching callers.
 */
interface TwoDLiveProvider
{
    /**
     * Fetch the latest 2D snapshot from the upstream.
     *
     * @throws TwoDProviderException on transport failure, a non-2xx response,
     *                               or a payload that is not a JSON object.
     */
    public function fetch(): TwoDLiveSnapshot;
}
