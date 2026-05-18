<?php

namespace App\Services\BettingDistribution;

use App\Models\OddSetting;
use App\Models\TemporaryOddAdjustment;
use App\Services\Service;
use DomainException;
use Illuminate\Support\Facades\DB;

class TemporaryOddAdjustmentService extends Service
{
    public function adjustOdds(
        string $date,
        string $opentime,
        string $betType,
        string $currency,
        array $adjustments,
        string $adminId
    ): array {
        $this->assertPeriodNotSettled($date, $opentime);

        $baseOdd = $this->resolveBaseOdd($betType, $currency);

        $applied = [];

        DB::transaction(function () use ($date, $opentime, $betType, $currency, $adjustments, $baseOdd, $adminId, &$applied): void {
            foreach ($adjustments as $adjustment) {
                $number = (int) $adjustment['number'];
                $tempOdd = (string) $adjustment['temp_odd'];

                TemporaryOddAdjustment::updateOrCreate(
                    [
                        'bet_type' => $betType,
                        'currency' => $currency,
                        'number' => $number,
                        'target_opentime' => $opentime,
                        'stock_date' => $date,
                    ],
                    [
                        'base_odd' => $baseOdd,
                        'adjusted_odd' => $tempOdd,
                        'created_by' => $adminId,
                    ]
                );

                $applied[] = [
                    'number' => $number,
                    'base_odd' => number_format((float) $baseOdd, 2, '.', ''),
                    'adjusted_odd' => number_format((float) $tempOdd, 2, '.', ''),
                    'status' => 'updated',
                ];
            }
        });

        return [
            'period' => [
                'target_opentime' => $opentime,
                'stock_date' => $date,
            ],
            'adjustments_applied' => count($applied),
            'adjustments' => $applied,
            'updated_at' => now('Asia/Bangkok')->toIso8601String(),
        ];
    }

    public function getAppliedOdds(
        string $date,
        string $opentime,
        string $betType,
        string $currency
    ): array {
        return TemporaryOddAdjustment::query()
            ->where('stock_date', $date)
            ->where('target_opentime', $opentime)
            ->where('bet_type', $betType)
            ->where('currency', $currency)
            ->orderBy('number')
            ->get()
            ->map(function (TemporaryOddAdjustment $odd): array {
                $base = (float) $odd->base_odd;
                $adjusted = (float) $odd->adjusted_odd;
                $diffPercent = $base > 0
                    ? (($adjusted - $base) / $base) * 100
                    : 0.0;

                return [
                    'number' => $odd->number,
                    'base_odd' => number_format($base, 2, '.', ''),
                    'adjusted_odd' => number_format($adjusted, 2, '.', ''),
                    'difference_percent' => number_format($diffPercent, 2, '.', ''),
                    'applied_at' => $odd->created_at->toIso8601String(),
                ];
            })
            ->all();
    }

    public function resetOdds(
        string $date,
        string $opentime,
        string $betType,
        string $currency
    ): array {
        $count = TemporaryOddAdjustment::query()
            ->where('stock_date', $date)
            ->where('target_opentime', $opentime)
            ->where('bet_type', $betType)
            ->where('currency', $currency)
            ->delete();

        return [
            'period' => [
                'target_opentime' => $opentime,
                'stock_date' => $date,
            ],
            'reset_count' => $count,
            'reset_at' => now('Asia/Bangkok')->toIso8601String(),
        ];
    }

    private function assertPeriodNotSettled(string $date, string $opentime): void
    {
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
            throw new DomainException('Cannot adjust odds for a settled period.');
        }
    }

    private function resolveBaseOdd(string $betType, string $currency): string
    {
        $odd = OddSetting::query()
            ->where('bet_type', $betType)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->value('odd');

        if ($odd === null) {
            throw new DomainException("No active odd setting found for {$betType}/{$currency}.");
        }

        return number_format((float) $odd, 2, '.', '');
    }
}
