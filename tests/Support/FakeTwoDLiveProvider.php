<?php

namespace Tests\Support;

use App\Contracts\TwoDLiveProvider;
use App\Exceptions\TwoDProviderException;
use App\Support\TwoD\TwoDLiveSnapshot;

/**
 * Test double for {@see TwoDLiveProvider}. Bind it in place of the real provider
 * to exercise consumers without any HTTP:
 *
 *   $this->app->instance(TwoDLiveProvider::class, new FakeTwoDLiveProvider($snapshot));
 *
 * Pass several snapshots to simulate retry sequences (each is returned once, then
 * the last one repeats), or use {@see throwing()} to simulate an upstream failure.
 */
class FakeTwoDLiveProvider implements TwoDLiveProvider
{
    /** @var TwoDLiveSnapshot[] */
    private array $snapshots;

    public function __construct(
        TwoDLiveSnapshot|array $snapshots = [],
        private readonly ?TwoDProviderException $exception = null,
    ) {
        $this->snapshots = $snapshots instanceof TwoDLiveSnapshot ? [$snapshots] : array_values($snapshots);
    }

    public static function throwing(TwoDProviderException $exception): self
    {
        return new self([], $exception);
    }

    public function fetch(): TwoDLiveSnapshot
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->snapshots === []) {
            throw new TwoDProviderException('FakeTwoDLiveProvider has no snapshots configured.');
        }

        return count($this->snapshots) > 1 ? array_shift($this->snapshots) : $this->snapshots[0];
    }
}
