<?php

namespace App\Services\BettingDistribution;

use App\Services\Service;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Refuses edits to a 2D period whose result has already been settled. Shared by
 * every control that is scoped to a period — closed/limited numbers and hot
 * first digits — so they cannot drift apart on what "too late" means.
 */
class PeriodSettlementGuard extends Service
{
    public function assertPeriodNotSettled(string $date, string $opentime): void
    {
        // 3D controls belong to the open draw, not a dated 2D slot, so there is
        // no settled period to protect here.
        if ($opentime === ThreeDDrawScope::OPENTIME_SENTINEL) {
            return;
        }

        $settled = DB::table('bet_settlement_runs')
            ->whereNotNull('settled_at')
            ->where(function ($q) use ($date, $opentime): void {
                $q->whereExists(function ($sub) use ($date, $opentime): void {
                    $sub->select(DB::raw(1))
                        ->from('two_d_results')
                        ->whereColumn('two_d_results.id', 'bet_settlement_runs.two_d_result_id')
                        ->whereDate('two_d_results.stock_date', $date)
                        ->where('two_d_results.open_time', $opentime);
                });
            })
            ->exists();

        if ($settled) {
            throw new DomainException('Cannot modify number controls for a settled period.');
        }
    }
}
