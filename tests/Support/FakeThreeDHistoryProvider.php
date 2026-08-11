<?php

namespace Tests\Support;

use App\Contracts\ThreeDHistoryProvider;
use App\Exceptions\ThreeDProviderException;
use App\Support\ThreeD\ThreeDDraw;

/**
 * Test double for {@see ThreeDHistoryProvider}. Bind it in place of the real
 * provider to exercise consumers without any HTTP:
 *
 *   $this->app->instance(ThreeDHistoryProvider::class, new FakeThreeDHistoryProvider($entries));
 *
 * Use {@see throwing()} to simulate an upstream failure or an exhausted budget.
 */
class FakeThreeDHistoryProvider implements ThreeDHistoryProvider
{
    public int $calls = 0;

    /**
     * @param  list<ThreeDDraw>  $entries
     */
    public function __construct(
        private readonly array $entries = [],
        private readonly ?ThreeDProviderException $exception = null,
    ) {}

    public static function throwing(ThreeDProviderException $exception): self
    {
        return new self([], $exception);
    }

    public function fetch(): array
    {
        $this->calls++;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->entries;
    }
}
