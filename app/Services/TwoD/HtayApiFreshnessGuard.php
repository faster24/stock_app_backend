<?php

namespace App\Services\TwoD;

use App\Models\TwoDResult;
use App\Services\Set\TradingCalendar;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Guards against accepting a stale, carried-over 2D value from HtayApi.
 *
 * Unlike thaistock2d (which shows "--" until a slot's result is posted),
 * HtayApi's morning/evening fields are always populated — there is no built-in
 * "not ready yet" signal, so freshness has to be inferred.
 *
 * Wall-clock time is the primary authority: a slot's number is published at its
 * open_time (a Myanmar time — 12:01 MMT is 12:31 in the Bangkok-based
 * scheduler), so anything read before that instant is necessarily the previous
 * trading day's value carried over. Once publication is comfortably past,
 * whatever sits in the block IS today's number and is accepted unconditionally.
 *
 * Comparing against the previously stored value is retained only for the narrow
 * window right at publication, where an upstream lag of a few seconds can still
 * be serving the old number. It is deliberately NOT the standing authority: as
 * the sole check it silently dropped any day whose number legitimately repeated
 * the stored one (~1% of days), and because the baseline only advances when a
 * row is written, one such collision blacklisted that value permanently — the
 * failure mode that kept the 12:01 slot empty with a stored baseline of '85'.
 */
class HtayApiFreshnessGuard
{
    /** Market timezone — matches the scheduler and the SET trading calendar. */
    private const MARKET_TZ = 'Asia/Bangkok';

    /** Slot labels ("12:01"/"16:30") are Myanmar times, 30 minutes behind. */
    private const SLOT_TZ = 'Asia/Yangon';

    /**
     * Minutes after publication during which upstream may still be serving the
     * previous value, so a value match is treated as carry-over. Past this the
     * number is trusted on time alone. Sized to clear the retry cadence's first
     * couple of attempts, so a legitimate repeat is picked up on a later one
     * rather than being lost for the day.
     */
    private const CARRY_OVER_GRACE_MINUTES = 10;

    public function __construct(private readonly TradingCalendar $calendar) {}

    public function isFresh(string $openTime, string $twod): bool
    {
        $now = Carbon::now(self::MARKET_TZ);

        // No draw on weekends/holidays — HtayApi keeps serving the last trading
        // day's numbers, and no amount of waiting makes them today's.
        if (! $this->calendar->isTradingDay($now)) {
            return false;
        }

        $publishedAt = $this->publicationInstant($openTime);

        if ($publishedAt === null) {
            // Unparseable slot label: fall back to the value comparison alone
            // rather than trusting a time gate that could not be evaluated.
            return $this->differsFromStored($openTime, $twod);
        }

        if ($now->lessThan($publishedAt)) {
            return false;
        }

        if ($now->greaterThanOrEqualTo($publishedAt->copy()->addMinutes(self::CARRY_OVER_GRACE_MINUTES))) {
            return true;
        }

        return $this->differsFromStored($openTime, $twod);
    }

    /**
     * The instant today's number for this slot becomes available.
     */
    private function publicationInstant(string $openTime): ?Carbon
    {
        try {
            $date = Carbon::now(self::SLOT_TZ)->toDateString();

            return Carbon::createFromFormat('Y-m-d H:i', "{$date} {$openTime}", self::SLOT_TZ)
                ->setTimezone(self::MARKET_TZ);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the value differs from the most recent earlier day stored for
     * this slot. An identical value that early is upstream carry-over.
     */
    private function differsFromStored(string $openTime, string $twod): bool
    {
        $previous = TwoDResult::query()
            ->where('open_time', 'like', $openTime.'%')
            ->whereDate('stock_date', '<', Carbon::now(self::MARKET_TZ)->toDateString())
            ->latest('stock_date')
            ->latest('id')
            ->first();

        if ($previous === null || $previous->twod === null) {
            return true;
        }

        return $previous->twod !== $twod;
    }
}
