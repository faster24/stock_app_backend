<?php

namespace App\Contracts;

use App\Enums\SetSession;
use App\Exceptions\SetScraperException;
use App\Support\Set\SetScrapeResult;

/**
 * Fetches a SET index reading for a session. The concrete implementation owns
 * the headless-browser transport; tests bind a fake. This is the seam that keeps
 * the browser out of the capture service and settlement path.
 */
interface SetScraper
{
    /**
     * @throws SetScraperException on transport failure, process error/timeout, or bad output.
     */
    public function capture(SetSession $session): SetScrapeResult;
}
