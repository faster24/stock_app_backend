<?php

namespace App\Services\BettingDistribution;

use App\Enums\BetType;
use App\Models\ThreeDResult;
use App\Services\Service;
use Illuminate\Support\Carbon;

/**
 * Resolves the currently open 3D draw.
 *
 * 2D closes four times a day, so its number controls and temporary odds are
 * keyed by (stock_date, target_opentime). 3D has neither: a draw runs from the
 * previous result until the next one is entered, which can span several days.
 * Storing 3D rows under "today" made a break expire at midnight while the draw
 * it was protecting was still open — so every 3D row is anchored to the date of
 * the result that opened the draw instead, and every reader resolves the same
 * anchor. When a result lands, the anchor moves and the old rows stop applying.
 */
class ThreeDDrawScope extends Service
{
    /** Anchor used before the very first 3D result exists. */
    public const EPOCH_ANCHOR = '1970-01-01';

    /** The 3D sentinel in number_controls / temporary_odd_adjustments.target_opentime. */
    public const OPENTIME_SENTINEL = '';

    /**
     * Date every control and temporary odd of the open draw is stored under.
     */
    public function anchorDate(): string
    {
        return $this->windowStart() ?? self::EPOCH_ANCHOR;
    }

    /**
     * First bet stock_date belonging to the open draw, or null when no 3D result
     * has ever been entered (every 3D bet is then still in scope).
     *
     * Inclusive, matching the settlement bounds in BetSettlementService: a bet
     * placed on a result day belongs to the next draw.
     */
    public function windowStart(): ?string
    {
        $latest = ThreeDResult::query()
            ->orderByDesc('stock_date')
            ->value('stock_date');

        return $latest !== null ? Carbon::parse($latest)->toDateString() : null;
    }

    /**
     * Storage date for a control / temporary odd: the draw anchor for 3D, the
     * caller's own date for 2D.
     */
    public function resolveStorageDate(string $betType, string $date): string
    {
        return $betType === BetType::THREE_D->value ? $this->anchorDate() : $date;
    }

    public function isThreeD(string $betType): bool
    {
        return $betType === BetType::THREE_D->value;
    }
}
