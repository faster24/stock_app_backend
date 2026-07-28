<?php

namespace App\Services\TwoD;

use App\Models\TwoDResult;
use Illuminate\Support\Carbon;

/**
 * Guards against accepting a stale, carried-over 2D value from HtayApi.
 *
 * Unlike thaistock2d (which shows "--" until a slot's result is posted),
 * HtayApi's morning/evening fields are always populated — there is no
 * built-in "not ready yet" signal. This compares a freshly fetched value
 * against whatever this system already has stored for that slot from the
 * most recent earlier trading day: an identical value is treated as
 * not-yet-updated, since a real day's 2D number practically never repeats
 * the immediately-prior day's number for the same slot.
 */
class HtayApiFreshnessGuard
{
    public function isFresh(string $openTime, string $twod): bool
    {
        $today = Carbon::now('Asia/Bangkok')->toDateString();

        $previous = TwoDResult::query()
            ->where('open_time', 'like', $openTime.'%')
            ->whereDate('stock_date', '<', $today)
            ->latest('stock_date')
            ->latest('id')
            ->first();

        if ($previous === null || $previous->twod === null) {
            return true;
        }

        return $previous->twod !== $twod;
    }
}
