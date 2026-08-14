<?php

use App\Services\BettingDistribution\ThreeDDrawScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 3D number controls and temporary odds used to be stored under the calendar day
 * they were created on, which made a break lapse at midnight even though the draw
 * was still open. They are now anchored to the date of the result that opened the
 * draw. This moves the rows of the open draw onto that anchor, keeping the newest
 * row per number (the composite unique key allows only one), and drops rows that
 * belong to draws already settled.
 */
return new class extends Migration
{
    private const SENTINEL = ThreeDDrawScope::OPENTIME_SENTINEL;

    public function up(): void
    {
        $scope = app(ThreeDDrawScope::class);

        foreach (['number_controls', 'temporary_odd_adjustments'] as $table) {
            $this->reanchor($table, $scope->anchorDate(), $scope->windowStart());
        }
    }

    public function down(): void
    {
        // The original per-day dates are not recoverable, and re-splitting one
        // draw-wide row across days would be a guess. Nothing to reverse.
    }

    private function reanchor(string $table, string $anchor, ?string $windowStart): void
    {
        // Rows dated before the open draw belonged to draws that have settled.
        if ($windowStart !== null) {
            DB::table($table)
                ->where('bet_type', '3D')
                ->where('target_opentime', self::SENTINEL)
                ->whereDate('stock_date', '<', $windowStart)
                ->delete();
        }

        $rows = DB::table($table)
            ->where('bet_type', '3D')
            ->where('target_opentime', self::SENTINEL)
            ->orderByDesc('stock_date')
            ->orderByDesc('created_at')
            ->get();

        $seen = [];
        $keepIds = [];
        $dropIds = [];

        foreach ($rows as $row) {
            $key = $row->currency.'|'.$row->number;

            if (isset($seen[$key])) {
                $dropIds[] = $row->id;

                continue;
            }

            $seen[$key] = true;
            $keepIds[] = $row->id;
        }

        if ($dropIds !== []) {
            DB::table($table)->whereIn('id', $dropIds)->delete();
        }

        if ($keepIds !== []) {
            DB::table($table)->whereIn('id', $keepIds)->update(['stock_date' => $anchor]);
        }
    }
};
