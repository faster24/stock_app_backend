<?php

namespace App\Services\BettingDistribution;

use App\Jobs\BroadcastNumberControlsJob;
use App\Models\DigitControl;
use App\Services\Service;
use Illuminate\Support\Facades\DB;

/**
 * "Hot" first digits: an admin shuts a whole row of the 2D board — every number
 * starting with that digit — in one action instead of closing ten numbers.
 *
 * A row's existence is the flag; there is no is_closed column and no separate
 * reopen endpoint, because setHotDigits() is a replace of the whole period set.
 */
class DigitControlService extends Service
{
    public function __construct(private readonly PeriodSettlementGuard $settlementGuard) {}

    /**
     * Replace the period's hot digits with exactly $digits.
     *
     * @param  list<int|string>  $digits
     */
    public function setHotDigits(
        string $date,
        string $opentime,
        string $betType,
        string $currency,
        array $digits,
        string $adminId
    ): array {
        $this->settlementGuard->assertPeriodNotSettled($date, $opentime);

        $wanted = $this->normalizeDigits($digits);

        DB::transaction(function () use ($date, $opentime, $betType, $currency, $wanted, $adminId): void {
            $scope = fn () => DigitControl::query()
                ->where('bet_type', $betType)
                ->where('currency', $currency)
                ->where('target_opentime', $opentime)
                ->where('stock_date', $date);

            // A POST carries the full desired set, so anything not in it is cleared.
            $scope()->when($wanted !== [], fn ($q) => $q->whereNotIn('digit', $wanted))->delete();

            foreach ($wanted as $digit) {
                DigitControl::updateOrCreate(
                    [
                        'bet_type' => $betType,
                        'currency' => $currency,
                        'digit' => $digit,
                        'target_opentime' => $opentime,
                        'stock_date' => $date,
                    ],
                    ['created_by' => $adminId],
                );
            }
        });

        $this->broadcast($betType, $currency, $opentime, $date);

        return [
            'period' => [
                'target_opentime' => $opentime,
                'stock_date' => $date,
            ],
            'hot_first_digits' => array_map(strval(...), $wanted),
            'updated_at' => now('Asia/Bangkok')->toIso8601String(),
        ];
    }

    /**
     * The period's hot first digits, ascending.
     *
     * @return list<int>
     */
    public function listHotDigits(
        string $date,
        string $opentime,
        string $betType,
        string $currency
    ): array {
        return DigitControl::query()
            ->where('bet_type', $betType)
            ->where('currency', $currency)
            ->where('target_opentime', $opentime)
            ->where('stock_date', $date)
            ->orderBy('digit')
            ->pluck('digit')
            ->map(intval(...))
            ->all();
    }

    /**
     * @param  list<int|string>  $digits
     * @return list<int> unique, ascending, 0-9 only
     */
    private function normalizeDigits(array $digits): array
    {
        $normalized = array_values(array_unique(array_map(intval(...), $digits)));
        $normalized = array_values(array_filter($normalized, fn (int $d): bool => $d >= 0 && $d <= 9));
        sort($normalized);

        return $normalized;
    }

    private function broadcast(string $betType, string $currency, string $opentime, string $date): void
    {
        // Same topic and payload as a number-control change: clients read it as
        // "this period's restrictions moved, refetch them".
        BroadcastNumberControlsJob::dispatch($betType, $currency, $opentime, $date)->afterCommit();
    }
}
