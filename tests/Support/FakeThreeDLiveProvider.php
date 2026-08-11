<?php

namespace Tests\Support;

use App\Contracts\ThreeDLiveProvider;
use App\Exceptions\ThreeDProviderException;
use App\Support\ThreeD\ThreeDDraw;

/**
 * Test double for {@see ThreeDLiveProvider}. Bind it in place of the real
 * provider to exercise consumers without any HTTP:
 *
 *   $this->app->instance(ThreeDLiveProvider::class, new FakeThreeDLiveProvider($draw));
 *
 * Pass null to simulate a vendor with nothing published, or use {@see throwing()}
 * for an upstream failure or an exhausted budget.
 */
class FakeThreeDLiveProvider implements ThreeDLiveProvider
{
    public int $calls = 0;

    public function __construct(
        private readonly ?ThreeDDraw $draw = null,
        private readonly ?ThreeDProviderException $exception = null,
    ) {}

    public static function throwing(ThreeDProviderException $exception): self
    {
        return new self(null, $exception);
    }

    public function fetch(): ?ThreeDDraw
    {
        $this->calls++;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->draw;
    }
}
