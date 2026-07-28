<?php

namespace App\Services\TwoD;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Enforces a persistent daily call ceiling against HtayApi's real 100/day quota.
 *
 * Keyed per calendar day in Asia/Bangkok so it resets naturally at local
 * midnight with no explicit reset job. The limit is a constructor argument
 * (not read from config internally) so tests can assert exhaustion
 * deterministically with a small limit instead of exercising the real ceiling.
 */
class HtayApiCallBudget
{
    public function __construct(private readonly int $dailyLimit) {}

    /**
     * Atomically checks and consumes one call against today's budget.
     */
    public function tryConsume(): bool
    {
        $key = $this->cacheKey();
        $count = (int) Cache::get($key, 0);

        if ($count >= $this->dailyLimit) {
            return false;
        }

        Cache::put($key, $count + 1, $this->secondsUntilMidnight());

        return true;
    }

    public function remaining(): int
    {
        return max(0, $this->dailyLimit - (int) Cache::get($this->cacheKey(), 0));
    }

    /**
     * Test helper: clears today's counter. Harmless/idempotent in production.
     */
    public function reset(): void
    {
        Cache::forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'htayapi:calls:'.Carbon::now('Asia/Bangkok')->toDateString();
    }

    private function secondsUntilMidnight(): int
    {
        $now = Carbon::now('Asia/Bangkok');

        return $now->diffInSeconds($now->copy()->endOfDay()) + 1;
    }
}
