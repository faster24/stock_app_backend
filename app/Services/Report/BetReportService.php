<?php

namespace App\Services\Report;

use App\Enums\WalletTransactionType;
use App\Models\Bet;
use App\Services\Service;
use Illuminate\Support\Facades\DB;

/**
 * Periodic bet P&L report (daily / monthly / yearly buckets on stock_date).
 *
 * money_in  = SUM(total_amount) of ACCEPTED bets.
 * money_out = SUM(wallet_transactions.amount) of BET_WIN credits referencing the bet,
 *             attributed to the bet's stock_date so in/out/profit align per bucket.
 *             Bets settled before the wallet ledger existed have no BET_WIN rows and
 *             therefore report zero money_out.
 */
class BetReportService extends Service
{
    private const SESSIONS = ['12:01:00', '16:30:00'];

    public function report(array $filters): array
    {
        $rows = $this->fetchRows($filters);

        $summary = $this->emptySlice();

        $mapped = [];

        foreach ($rows as $row) {
            $mapped[] = $this->mapRow($row);
            $this->accumulate($summary, $row, '');
        }

        return [
            'summary' => $this->finalizeSlice($summary),
            'rows' => $mapped,
        ];
    }

    public function csvRows(array $filters): array
    {
        $report = $this->report($filters);

        $lines = [];

        foreach ($report['rows'] as $row) {
            $twoD = $row['by_bet_type']['2D'];
            $threeD = $row['by_bet_type']['3D'];
            $s1201 = $row['by_session']['12:01:00'];
            $s1630 = $row['by_session']['16:30:00'];

            $lines[] = [
                $row['bucket'],
                $row['bet_count'],
                $row['win_count'],
                $row['money_in'],
                $row['money_out'],
                $row['profit'],
                $twoD['bet_count'],
                $twoD['money_in'],
                $twoD['money_out'],
                $twoD['profit'],
                $threeD['bet_count'],
                $threeD['money_in'],
                $threeD['money_out'],
                $threeD['profit'],
                $s1201['money_in'],
                $s1201['money_out'],
                $s1630['money_in'],
                $s1630['money_out'],
            ];
        }

        return $lines;
    }

    public function csvHeader(): array
    {
        return [
            'Period', 'Bets', 'Wins', 'Money In', 'Money Out', 'Profit',
            '2D Bets', '2D Money In', '2D Money Out', '2D Profit',
            '3D Bets', '3D Money In', '3D Money Out', '3D Profit',
            '12:01 Money In', '12:01 Money Out',
            '16:30 Money In', '16:30 Money Out',
        ];
    }

    private function fetchRows(array $filters): iterable
    {
        $bucketExpr = match ((string) $filters['granularity']) {
            'daily' => "DATE_FORMAT(b.stock_date, '%Y-%m-%d')",
            'monthly' => "DATE_FORMAT(b.stock_date, '%Y-%m')",
            'yearly' => "DATE_FORMAT(b.stock_date, '%Y')",
        };

        $query = DB::table('bets as b')
            ->leftJoinSub(
                DB::table('wallet_transactions')
                    ->where('type', WalletTransactionType::BET_WIN->value)
                    ->where('reference_type', Bet::class)
                    ->groupBy('reference_id')
                    ->selectRaw('reference_id, COALESCE(SUM(amount), 0) as win_amount'),
                'w',
                'w.reference_id',
                '=',
                'b.id'
            )
            ->whereDate('b.stock_date', '>=', (string) $filters['from'])
            ->whereDate('b.stock_date', '<=', (string) $filters['to']);

        if (array_key_exists('bet_type', $filters)) {
            $query->where('b.bet_type', (string) $filters['bet_type']);
        }

        $selects = [
            "{$bucketExpr} as bucket",
            $this->sliceColumns('1=1', ''),
            $this->sliceColumns("b.bet_type = '2D'", 'twod_'),
            $this->sliceColumns("b.bet_type = '3D'", 'threed_'),
            $this->sliceColumns("b.bet_type = '2D' AND b.target_opentime = '12:01:00'", 's1201_'),
            $this->sliceColumns("b.bet_type = '2D' AND b.target_opentime = '16:30:00'", 's1630_'),
        ];

        return $query
            ->selectRaw(implode(",\n", $selects))
            ->groupBy(DB::raw($bucketExpr))
            ->orderBy(DB::raw($bucketExpr))
            ->get();
    }

    private function sliceColumns(string $condition, string $prefix): string
    {
        return "SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END) as {$prefix}bet_count,
            SUM(CASE WHEN {$condition} AND b.bet_result_status = 'WON' THEN 1 ELSE 0 END) as {$prefix}win_count,
            COALESCE(SUM(CASE WHEN {$condition} AND b.status = 'ACCEPTED' THEN b.total_amount ELSE 0 END), 0) as {$prefix}money_in,
            COALESCE(SUM(CASE WHEN {$condition} THEN COALESCE(w.win_amount, 0) ELSE 0 END), 0) as {$prefix}money_out";
    }

    private function mapRow(object $row): array
    {
        return array_merge(
            ['bucket' => (string) $row->bucket],
            $this->sliceFromRow($row, ''),
            [
                'by_bet_type' => [
                    '2D' => $this->sliceFromRow($row, 'twod_'),
                    '3D' => $this->sliceFromRow($row, 'threed_'),
                ],
                'by_session' => [
                    self::SESSIONS[0] => $this->sliceFromRow($row, 's1201_'),
                    self::SESSIONS[1] => $this->sliceFromRow($row, 's1630_'),
                ],
            ]
        );
    }

    private function sliceFromRow(object $row, string $prefix): array
    {
        $moneyIn = (float) $row->{$prefix.'money_in'};
        $moneyOut = (float) $row->{$prefix.'money_out'};

        return [
            'bet_count' => (int) $row->{$prefix.'bet_count'},
            'win_count' => (int) $row->{$prefix.'win_count'},
            'money_in' => $this->toMoney($moneyIn),
            'money_out' => $this->toMoney($moneyOut),
            'profit' => $this->toMoney($moneyIn - $moneyOut),
        ];
    }

    private function emptySlice(): array
    {
        return [
            'bet_count' => 0,
            'win_count' => 0,
            'money_in' => 0.0,
            'money_out' => 0.0,
        ];
    }

    private function accumulate(array &$slice, object $row, string $prefix): void
    {
        $slice['bet_count'] += (int) $row->{$prefix.'bet_count'};
        $slice['win_count'] += (int) $row->{$prefix.'win_count'};
        $slice['money_in'] += (float) $row->{$prefix.'money_in'};
        $slice['money_out'] += (float) $row->{$prefix.'money_out'};
    }

    private function finalizeSlice(array $slice): array
    {
        return [
            'bet_count' => $slice['bet_count'],
            'win_count' => $slice['win_count'],
            'money_in' => $this->toMoney($slice['money_in']),
            'money_out' => $this->toMoney($slice['money_out']),
            'profit' => $this->toMoney($slice['money_in'] - $slice['money_out']),
        ];
    }

    private function toMoney(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
