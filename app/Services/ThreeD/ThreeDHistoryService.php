<?php

namespace App\Services\ThreeD;

use App\Contracts\ThreeDHistoryProvider;
use App\Services\Service;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Serves the 3D history list from a shared cache.
 *
 * Same shape as {@see \App\Services\TwoD\TwoDLiveTickerService} and for the same
 * reason: every client reads through this one service, so upstream cost is a
 * function of the TTL alone rather than of how many people have the app open,
 * against a daily key quota shared with the 2D ticker and settlement.
 *
 * Two layers, deliberately separate:
 *
 *   - FRESH_KEY holds the list for a normal TTL. While present, no upstream
 *     call happens at all.
 *   - LAST_KEY holds the most recent successful list for a week. It is served
 *     when the provider fails or the budget is spent, so the page degrades to
 *     a slightly stale list instead of an error.
 *
 * The TTL can afford to be generous: 3D draws on the 1st and 16th, so the list
 * changes twice a month. The refresh is wrapped in a lock so a cold cache does
 * not turn N concurrent requests into N upstream calls.
 */
class ThreeDHistoryService extends Service
{
    private const FRESH_KEY = 'htayapi:threed-history:fresh';

    private const LAST_KEY = 'htayapi:threed-history:last';

    private const LOCK_KEY = 'htayapi:threed-history:refresh';

    /** Long enough to cover the provider's own HTTP timeout. */
    private const LOCK_SECONDS = 30;

    /** Retains the fallback list across a multi-day outage. */
    private const LAST_TTL_SECONDS = 604800;

    public function __construct(
        private readonly ThreeDHistoryProvider $provider,
    ) {}

    /**
     * @return array{results: list<array{threed: string, stock_date: string}>, stale: bool}
     */
    public function current(): array
    {
        $fresh = Cache::get(self::FRESH_KEY);

        if (is_array($fresh)) {
            return ['results' => $fresh, 'stale' => false];
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            // Another request is already refreshing. Serving the previous list
            // keeps this request fast and, more importantly, keeps it from
            // becoming a second upstream call.
            return $this->lastKnown();
        }

        try {
            $entries = $this->provider->fetch();
        } catch (Throwable) {
            // Covers a genuine upstream failure and an exhausted daily budget
            // alike. Settlement shares that budget, so this must never retry
            // its way through it.
            return $this->lastKnown();
        } finally {
            $lock->release();
        }

        if ($entries === []) {
            // An empty list is indistinguishable from a vendor hiccup here, and
            // overwriting a good cache with nothing would be the worse guess.
            return $this->lastKnown();
        }

        $payload = array_map(static fn ($entry) => $entry->toArray(), $entries);

        Cache::put(self::FRESH_KEY, $payload, $this->freshTtlSeconds());
        Cache::put(self::LAST_KEY, $payload, self::LAST_TTL_SECONDS);

        return ['results' => $payload, 'stale' => false];
    }

    /**
     * @return array{results: list<array{threed: string, stock_date: string}>, stale: bool}
     */
    private function lastKnown(): array
    {
        $last = Cache::get(self::LAST_KEY);

        if (is_array($last)) {
            return ['results' => $last, 'stale' => true];
        }

        return ['results' => [], 'stale' => true];
    }

    private function freshTtlSeconds(): int
    {
        return (int) config('services.twod.htayapi.threed_history_ttl', 3600);
    }
}
