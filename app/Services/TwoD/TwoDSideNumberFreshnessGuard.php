<?php

namespace App\Services\TwoD;

use App\Enums\TwoDSideSlot;
use App\Models\TwoDSideNumber;
use App\Services\Set\TradingCalendar;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Guards against storing a stale, carried-over `modern`/`internet` pair.
 *
 * HtayApi has no "not ready yet" signal: the morning/evening blocks are always
 * populated, and on a day with no draw they keep serving the last trading day's
 * values. Storing those as today's is exactly the failure that put a stale '85'
 * in the 12:01 slot on 2026-07-29.
 *
 * Three defences, strongest first:
 *
 *  1. Structural — one capture run handles ONE slot, and reads only that slot's
 *     block. At 10:02 Bangkok the `evening` block legitimately still holds
 *     yesterday's numbers; the morning run never looks at it. Cross-slot
 *     carry-over is impossible by construction rather than by inference.
 *  2. Calendar — no draw on weekends or SET holidays, so nothing published on
 *     those days is ever today's.
 *  3. Clock, then value — before the slot's publication instant the data is
 *     necessarily yesterday's. Comfortably past it, whatever sits in the block
 *     IS today's. Only in the narrow window between is the previous stored pair
 *     consulted.
 *
 * The time logic below is deliberately duplicated from
 * {@see HtayApiFreshnessGuard} rather than extracted into a shared helper: that
 * guard sits directly on the settlement path, and a refactor there buys nothing
 * functional here. Unify the two once this feature has proven itself.
 */
class TwoDSideNumberFreshnessGuard
{
    /** Market timezone — matches the scheduler and the SET trading calendar. */
    private const MARKET_TZ = 'Asia/Bangkok';

    /** Slot labels ("09:30"/"14:00") are Myanmar times, 30 minutes behind. */
    private const SLOT_TZ = 'Asia/Yangon';

    /**
     * Minutes after publication during which upstream may still be serving the
     * previous value, so an identical pair is treated as carry-over. Past this
     * the numbers are trusted on time alone.
     */
    private const CARRY_OVER_GRACE_MINUTES = 10;

    public function __construct(private readonly TradingCalendar $calendar) {}

    public function isFresh(TwoDSideSlot $slot, ?string $modern, ?string $internet): bool
    {
        $now = Carbon::now(self::MARKET_TZ);

        if (! $this->calendar->isTradingDay($now)) {
            return false;
        }

        $publishedAt = $this->publicationInstant($slot);

        if ($publishedAt === null) {
            // Unparseable slot time: fall back to the value comparison alone
            // rather than trusting a time gate that could not be evaluated.
            return $this->differsFromStored($slot, $modern, $internet);
        }

        if ($now->lessThan($publishedAt)) {
            return false;
        }

        if ($now->greaterThanOrEqualTo($publishedAt->copy()->addMinutes(self::CARRY_OVER_GRACE_MINUTES))) {
            return true;
        }

        return $this->differsFromStored($slot, $modern, $internet);
    }

    /** The instant today's numbers for this slot become available. */
    private function publicationInstant(TwoDSideSlot $slot): ?Carbon
    {
        try {
            $date = Carbon::now(self::SLOT_TZ)->toDateString();

            return Carbon::createFromFormat('Y-m-d H:i', "{$date} {$slot->publicationTime()}", self::SLOT_TZ)
                ->setTimezone(self::MARKET_TZ);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether either half of the pair differs from the most recent earlier day
     * stored for this slot. An identical pair that early is upstream carry-over.
     *
     * Requiring BOTH halves to repeat before suspecting carry-over is what makes
     * this safe to use at all: the settlement guard's single-value comparison
     * rejected any day whose number legitimately repeated (~1 in 100), and here
     * that becomes ~1 in 10,000. Even then it only applies inside the grace
     * window, so a later attempt picks the pair up on time alone.
     */
    private function differsFromStored(TwoDSideSlot $slot, ?string $modern, ?string $internet): bool
    {
        $previous = TwoDSideNumber::query()
            ->where('slot', $slot->value)
            ->whereDate('result_date', '<', Carbon::now(self::MARKET_TZ)->toDateString())
            ->latest('result_date')
            ->latest('id')
            ->first();

        if ($previous === null) {
            return true;
        }

        return $previous->modern !== $modern || $previous->internet !== $internet;
    }
}
