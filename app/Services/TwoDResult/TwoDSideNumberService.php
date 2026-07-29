<?php

namespace App\Services\TwoDResult;

use App\Models\TwoDSideNumber;
use App\Services\Service;
use Illuminate\Database\Eloquent\Collection;

class TwoDSideNumberService extends Service
{
    /**
     * The side numbers for the five most recent recorded dates.
     *
     * Mirrors {@see TwoDResultService::lastFiveDays()} so the client can request
     * both in one round trip and filter each to today.
     *
     * Ordering is by date only. Intra-day ordering is not significant: at most
     * two rows exist per date and the client keys them by `slot`, not position.
     */
    public function lastFiveDays(): Collection
    {
        $latestFiveDates = TwoDSideNumber::query()
            ->select('result_date')
            ->distinct()
            ->orderByDesc('result_date')
            ->limit(5)
            ->pluck('result_date');

        return TwoDSideNumber::query()
            ->whereIn('result_date', $latestFiveDates)
            ->orderByDesc('result_date')
            ->orderByDesc('id')
            ->get();
    }
}
