<?php

namespace App\Services\ThreeD;

use App\Contracts\ThreeDLiveProvider;
use App\Services\Service;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Serves the current 3D draw from a shared cache.
 *
 * Same shape as {@see ThreeDHistoryService} and {@see \App\Services\TwoD\TwoDLiveTickerService},
 * and for the same reason: every client reads through this one service, so
 * upstream cost is a function of the TTL alone rather than of how many admins
 * have the page open, against a daily key quota shared with the 2D ticker,
 * the 3D history feed and settlement.
 *
 * Two layers, deliberately separate:
 *
 *   - FRESH_KEY holds the draw for a short TTL. While present, no upstream call
 *     happens at all.
 *   - LAST_KEY holds the most recent successful draw for a week. It is served
 *     when the provider fails or the budget is spent, so the card degrades to a
 *     slightly stale number instead of an error.
 *
 * The TTL is short (a minute by default) because this answers "is the result out
 * yet?" and sits behind a refresh button — a long TTL would make that button
 * look broken. Worst case is ~1440 calls/day on an admin-only surface.
 */
class ThreeDLiveService extends Service
{
    private const FRESH_KEY = 'htayapi:threed-live:fresh';

    private const LAST_KEY = 'htayapi:threed-live:last';

    private const LOCK_KEY = 'htayapi:threed-live:refresh';

    /** Long enough to cover the provider's own HTTP timeout. */
    private const LOCK_SECONDS = 30;

    /** Retains the fallback value across a multi-day outage. */
    private const LAST_TTL_SECONDS = 604800;

    public function __construct(
        private readonly ThreeDLiveProvider $provider,
    ) {}

    /**
     * @return array{live: ?array{threed: string, stock_date: string}, stale: bool}
     */
    public function current(): array
    {
        $fresh = Cache::get(self::FRESH_KEY);

        if (is_array($fresh)) {
            return ['live' => $fresh, 'stale' => false];
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            // Another request is already refreshing. Serving the previous draw
            // keeps this request fast and, more importantly, keeps it from
            // becoming a second upstream call.
            return $this->lastKnown();
        }

        try {
            $draw = $this->provider->fetch();
        } catch (Throwable) {
            // Covers a genuine upstream failure and an exhausted daily budget
            // alike. Settlement shares that budget, so this must never retry
            // its way through it.
            return $this->lastKnown();
        } finally {
            $lock->release();
        }

        if ($draw === null) {
            // The vendor has nothing to publish yet. Keep showing the previous
            // draw rather than blanking the card.
            return $this->lastKnown();
        }

        $payload = $draw->toArray();

        Cache::put(self::FRESH_KEY, $payload, $this->freshTtlSeconds());
        Cache::put(self::LAST_KEY, $payload, self::LAST_TTL_SECONDS);

        return ['live' => $payload, 'stale' => false];
    }

    /**
     * @return array{live: ?array{threed: string, stock_date: string}, stale: bool}
     */
    private function lastKnown(): array
    {
        $last = Cache::get(self::LAST_KEY);

        if (is_array($last)) {
            return ['live' => $last, 'stale' => true];
        }

        return ['live' => null, 'stale' => true];
    }

    private function freshTtlSeconds(): int
    {
        return (int) config('services.twod.htayapi.threed_live_ttl', 60);
    }
}
