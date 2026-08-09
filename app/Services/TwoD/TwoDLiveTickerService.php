<?php

namespace App\Services\TwoD;

use App\Contracts\TwoDLiveProvider;
use App\Support\TwoD\TwoDLiveData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Serves the home-screen live 2D ticker from a shared cache.
 *
 * Every client reads through this one service, so upstream cost is a function
 * of the TTL alone and not of how many people have the app open. Without it,
 * a 5s client poll would multiply by the user count against a shared daily key
 * quota.
 *
 * Two layers, deliberately separate:
 *
 *   - FRESH_KEY holds a short-lived snapshot. While it is present no upstream
 *     call happens at all.
 *   - LAST_KEY holds the most recent successful snapshot for a full day. It is
 *     what gets served when the provider fails or the daily budget is spent,
 *     so the ticker degrades to a slightly stale number instead of an error.
 *
 * The refresh is wrapped in a lock because the failure mode this service exists
 * to prevent reappears at every TTL expiry otherwise: N concurrent requests all
 * miss simultaneously and all call upstream. Losers of the lock serve LAST_KEY
 * rather than queueing, since a ticker value a few seconds old is worth more
 * than a held connection.
 */
class TwoDLiveTickerService
{
    private const FRESH_KEY = 'htayapi:live:fresh';

    private const LAST_KEY = 'htayapi:live:last';

    private const LOCK_KEY = 'htayapi:live:refresh';

    /** Long enough to cover the provider's own HTTP timeout. */
    private const LOCK_SECONDS = 30;

    /** Retains the fallback value across an overnight outage. */
    private const LAST_TTL_SECONDS = 86400;

    /** Ticker cadence is a Myanmar-time concern, matching the client's window. */
    private const DISPLAY_TZ = 'Asia/Yangon';

    /**
     * Minute-of-day windows (MMT) around each draw, when the number actually
     * moves and the TTL tightens. 12:01 and 16:30 are the settlement slots.
     */
    private const HOT_WINDOWS = [
        [11 * 60 + 30, 12 * 60 + 10],
        [15 * 60 + 45, 16 * 60 + 40],
    ];

    private const MARKET_OPEN_MINUTES = 9 * 60;

    private const MARKET_CLOSE_MINUTES = 17 * 60;

    public function __construct(
        private readonly TwoDLiveProvider $provider,
    ) {}

    /**
     * @return array{twod: ?string, set: ?string, value: ?string, time: ?string, stale: bool}
     */
    public function current(): array
    {
        $fresh = Cache::get(self::FRESH_KEY);

        if (is_array($fresh)) {
            return $fresh + ['stale' => false];
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            // Another request is already refreshing. Serving the previous value
            // keeps this request fast and, more importantly, keeps it from
            // becoming a second upstream call.
            return $this->lastKnown();
        }

        try {
            $snapshot = $this->provider->fetch();
        } catch (Throwable) {
            // Covers both a genuine upstream failure and an exhausted daily
            // budget (HtayApiProvider throws for both). Settlement shares that
            // budget, so the ticker must never retry its way through it.
            return $this->lastKnown();
        } finally {
            $lock->release();
        }

        if ($snapshot->live === null) {
            return $this->lastKnown();
        }

        $payload = $this->toPayload($snapshot->live);

        Cache::put(self::FRESH_KEY, $payload, $this->freshTtlSeconds());
        Cache::put(self::LAST_KEY, $payload, self::LAST_TTL_SECONDS);

        return $payload + ['stale' => false];
    }

    /**
     * @return array{twod: ?string, set: ?string, value: ?string, time: ?string, stale: bool}
     */
    private function lastKnown(): array
    {
        $last = Cache::get(self::LAST_KEY);

        if (is_array($last)) {
            return $last + ['stale' => true];
        }

        return ['twod' => null, 'set' => null, 'value' => null, 'time' => null, 'stale' => true];
    }

    /**
     * @return array{twod: ?string, set: ?string, value: ?string, time: ?string}
     */
    private function toPayload(TwoDLiveData $live): array
    {
        return [
            'twod' => $live->twod,
            'set' => $live->set,
            'value' => $live->value,
            'time' => $live->dateTime ?? $live->time,
        ];
    }

    /**
     * TTL for the current moment, tightening around the draws and relaxing
     * overnight. Configurable so cadence can be retuned without a deploy.
     */
    private function freshTtlSeconds(): int
    {
        $minutes = $this->minutesOfDay();

        foreach (self::HOT_WINDOWS as [$start, $end]) {
            if ($minutes >= $start && $minutes < $end) {
                return (int) config('services.twod.htayapi.live_ttl_hot', 5);
            }
        }

        if ($minutes >= self::MARKET_OPEN_MINUTES && $minutes < self::MARKET_CLOSE_MINUTES) {
            return (int) config('services.twod.htayapi.live_ttl_warm', 20);
        }

        return (int) config('services.twod.htayapi.live_ttl_cold', 300);
    }

    private function minutesOfDay(): int
    {
        $now = Carbon::now(self::DISPLAY_TZ);

        return $now->hour * 60 + $now->minute;
    }
}
