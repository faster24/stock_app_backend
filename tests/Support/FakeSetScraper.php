<?php

namespace Tests\Support;

use App\Contracts\SetScraper;
use App\Enums\SetSession;
use App\Exceptions\SetScraperException;
use App\Support\Set\SetScrapeResult;

/**
 * Test double for {@see SetScraper}. Bind in place of NodeSetScraper to exercise
 * the capture flow without a browser:
 *
 *   $this->app->instance(SetScraper::class, FakeSetScraper::returning($result));
 */
class FakeSetScraper implements SetScraper
{
    /** @var SetSession[] sessions this fake was asked to capture */
    public array $captured = [];

    public function __construct(
        private readonly ?SetScrapeResult $result = null,
        private readonly ?SetScraperException $exception = null,
    ) {}

    public static function returning(SetScrapeResult $result): self
    {
        return new self($result);
    }

    public static function throwing(SetScraperException $exception): self
    {
        return new self(null, $exception);
    }

    /** Convenience builder for a stabilized reading. */
    public static function reading(
        string $last = '1644.39',
        string $open = '1624.85',
        string $value = '89284959005',
        string $marketStatus = 'Closed',
        bool $stabilized = true,
    ): SetScrapeResult {
        return new SetScrapeResult(
            httpStatus: 200,
            marketStatus: $marketStatus,
            marketDateTime: '2026-07-25T16:35:00+07:00',
            indexLast: $last,
            indexOpen: $open,
            value: $value,
            computed2d: null,
            stabilized: $stabilized,
            attempts: 1,
            raw: ['index' => ['last' => $last, 'open' => $open, 'value' => $value]],
        );
    }

    public function capture(SetSession $session): SetScrapeResult
    {
        $this->captured[] = $session;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->result === null) {
            throw new SetScraperException('FakeSetScraper has no result configured.');
        }

        return $this->result;
    }
}
