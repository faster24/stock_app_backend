<?php

namespace App\Services\BettingDistribution;

use App\Enums\BetStatus;
use App\Jobs\BroadcastNumberControlsJob;
use App\Models\NumberControl;
use App\Services\Service;
use DomainException;
use Illuminate\Support\Facades\DB;

class NumberControlService extends Service
{
    public function setControls(
        string $date,
        string $opentime,
        string $betType,
        string $currency,
        array $controls,
        string $adminId
    ): array {
        $this->assertPeriodNotSettled($date, $opentime);

        $applied = [];

        DB::transaction(function () use ($date, $opentime, $betType, $currency, $controls, $adminId, &$applied): void {
            foreach ($controls as $control) {
                $number = (int) $control['number'];
                $action = (string) $control['action'];
                $isClosed = $action === 'close';
                $salesLimit = $isClosed ? null : number_format((float) $control['sales_limit'], 2, '.', '');

                NumberControl::updateOrCreate(
                    [
                        'bet_type' => $betType,
                        'currency' => $currency,
                        'number' => $number,
                        'target_opentime' => $opentime,
                        'stock_date' => $date,
                    ],
                    [
                        'is_closed' => $isClosed,
                        'sales_limit' => $salesLimit,
                        'created_by' => $adminId,
                    ]
                );

                $applied[] = [
                    'number' => $number,
                    'status' => $isClosed ? 'closed' : 'limited',
                    'sales_limit' => $salesLimit,
                ];
            }
        });

        $this->broadcast($betType, $currency, $opentime, $date);

        return [
            'period' => [
                'target_opentime' => $opentime,
                'stock_date' => $date,
            ],
            'controls_applied' => count($applied),
            'controls' => $applied,
            'updated_at' => now('Asia/Bangkok')->toIso8601String(),
        ];
    }

    public function reopen(
        string $date,
        string $opentime,
        string $betType,
        string $currency,
        array $numbers
    ): array {
        $this->assertPeriodNotSettled($date, $opentime);

        $count = NumberControl::query()
            ->where('stock_date', $date)
            ->where('target_opentime', $opentime)
            ->where('bet_type', $betType)
            ->where('currency', $currency)
            ->whereIn('number', array_map(intval(...), $numbers))
            ->delete();

        $this->broadcast($betType, $currency, $opentime, $date);

        return [
            'period' => [
                'target_opentime' => $opentime,
                'stock_date' => $date,
            ],
            'reopened_count' => $count,
            'reopened_at' => now('Asia/Bangkok')->toIso8601String(),
        ];
    }

    public function listControls(
        string $date,
        string $opentime,
        string $betType,
        string $currency
    ): array {
        $controls = NumberControl::query()
            ->where('stock_date', $date)
            ->where('target_opentime', $opentime)
            ->where('bet_type', $betType)
            ->where('currency', $currency)
            ->orderBy('number')
            ->get();

        if ($controls->isEmpty()) {
            return [];
        }

        $soldByNumber = $this->soldVolumesByNumber(
            $date,
            $opentime,
            $betType,
            $currency,
            $controls->pluck('number')->all(),
        );

        return $controls
            ->map(function (NumberControl $control) use ($soldByNumber): array {
                $sold = (float) ($soldByNumber[$control->number] ?? 0.0);
                $limit = $control->sales_limit !== null ? (float) $control->sales_limit : null;

                return [
                    'number' => $control->number,
                    'status' => $control->is_closed ? 'closed' : 'limited',
                    'sales_limit' => $limit !== null ? number_format($limit, 2, '.', '') : null,
                    'sold' => number_format($sold, 2, '.', ''),
                    'remaining' => $limit !== null
                        ? number_format(max(0, $limit - $sold), 2, '.', '')
                        : null,
                    'created_at' => $control->created_at->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * Sum of PENDING/ACCEPTED bet amounts per number for one period.
     * An '' opentime means 3D bets, stored with a NULL target_opentime.
     *
     * @return array<int, float> number => sold volume
     */
    public function soldVolumesByNumber(
        string $date,
        string $opentime,
        string $betType,
        string $currency,
        array $numbers,
        ?string $excludeBetId = null
    ): array {
        if ($numbers === []) {
            return [];
        }

        return DB::table('bet_numbers')
            ->join('bets', 'bets.id', '=', 'bet_numbers.bet_id')
            ->whereDate('bets.stock_date', $date)
            ->where('bets.bet_type', $betType)
            ->where('bets.currency', $currency)
            ->when(
                $opentime === '',
                fn ($q) => $q->whereNull('bets.target_opentime'),
                fn ($q) => $q->where('bets.target_opentime', $opentime),
            )
            ->whereIn('bets.status', [BetStatus::PENDING->value, BetStatus::ACCEPTED->value])
            ->when($excludeBetId !== null, fn ($q) => $q->where('bets.id', '!=', $excludeBetId))
            ->whereIn('bet_numbers.number', $numbers)
            ->groupBy('bet_numbers.number')
            ->select(['bet_numbers.number', DB::raw('COALESCE(SUM(bet_numbers.amount), 0) as sold')])
            ->pluck('sold', 'number')
            ->map(fn ($sold): float => (float) $sold)
            ->all();
    }

    private function broadcast(string $betType, string $currency, string $opentime, string $date): void
    {
        BroadcastNumberControlsJob::dispatch($betType, $currency, $opentime, $date)->afterCommit();
    }

    private function assertPeriodNotSettled(string $date, string $opentime): void
    {
        if ($opentime === '') {
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
