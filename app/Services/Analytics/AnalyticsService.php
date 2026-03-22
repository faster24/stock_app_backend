<?php

namespace App\Services\Analytics;

use App\Enums\BetPayoutStatus;
use App\Services\Service;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AnalyticsService extends Service
{
    public function kpis(array $filters): array
    {
        $query = DB::table('bets');
        $this->applyBetDateFilters($query, $filters);
        $this->applyOptionalBetFilters($query, $filters);

        $row = $query
            ->selectRaw(
                "COUNT(*) as total_bets,
                COUNT(DISTINCT user_id) as unique_bettors,
                COALESCE(SUM(total_amount), 0) as total_turnover,
                SUM(CASE WHEN status = 'ACCEPTED' THEN 1 ELSE 0 END) as accepted_count,
                SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN status = 'REFUNDED' THEN 1 ELSE 0 END) as refunded_count,
                SUM(CASE WHEN bet_result_status = 'WON' THEN 1 ELSE 0 END) as won_count,
                SUM(CASE WHEN bet_result_status = 'LOST' THEN 1 ELSE 0 END) as lost_count,
                SUM(CASE WHEN bet_result_status = 'INVALID' THEN 1 ELSE 0 END) as invalid_count,
                SUM(CASE WHEN payout_status = 'PAID_OUT' THEN 1 ELSE 0 END) as paid_out_count"
            )
            ->first();

        return [
            'total_bets' => (int) ($row->total_bets ?? 0),
            'unique_bettors' => (int) ($row->unique_bettors ?? 0),
            'total_turnover' => $this->toMoney($row->total_turnover ?? 0),
            'accepted_count' => (int) ($row->accepted_count ?? 0),
            'rejected_count' => (int) ($row->rejected_count ?? 0),
            'refunded_count' => (int) ($row->refunded_count ?? 0),
            'won_count' => (int) ($row->won_count ?? 0),
            'lost_count' => (int) ($row->lost_count ?? 0),
            'invalid_count' => (int) ($row->invalid_count ?? 0),
            'paid_out_count' => (int) ($row->paid_out_count ?? 0),
        ];
    }

    public function dailyTrends(array $filters): array
    {
        $query = DB::table('bets');
        $this->applyBetDateFilters($query, $filters);
        $this->applyOptionalBetFilters($query, $filters);

        return $query
            ->selectRaw(
                "stock_date as date,
                COUNT(*) as bet_count,
                COALESCE(SUM(total_amount), 0) as turnover,
                SUM(CASE WHEN bet_result_status = 'WON' THEN 1 ELSE 0 END) as won_count,
                SUM(CASE WHEN bet_result_status = 'LOST' THEN 1 ELSE 0 END) as lost_count,
                SUM(CASE WHEN status = 'REFUNDED' THEN 1 ELSE 0 END) as refund_count,
                SUM(CASE WHEN payout_status = 'PAID_OUT' THEN 1 ELSE 0 END) as paid_out_count"
            )
            ->groupBy('stock_date')
            ->orderBy('stock_date')
            ->get()
            ->map(fn ($row): array => [
                'date' => (string) $row->date,
                'bet_count' => (int) $row->bet_count,
                'turnover' => $this->toMoney($row->turnover),
                'won_count' => (int) $row->won_count,
                'lost_count' => (int) $row->lost_count,
                'refund_count' => (int) $row->refund_count,
                'paid_out_count' => (int) $row->paid_out_count,
            ])
            ->values()
            ->all();
    }

    public function statusDistribution(array $filters): array
    {
        $base = DB::table('bets');
        $this->applyBetDateFilters($base, $filters);
        $this->applyOptionalBetFilters($base, $filters);

        $total = (clone $base)->count();

        return [
            'total_bets' => $total,
            'status' => $this->statusBuckets((clone $base), 'status', $total),
            'bet_result_status' => $this->statusBuckets((clone $base), 'bet_result_status', $total),
            'payout_status' => $this->statusBuckets((clone $base), 'payout_status', $total),
        ];
    }

    public function payouts(array $filters): array
    {
        $base = DB::table('bets')
            ->where('payout_status', BetPayoutStatus::PAID_OUT->value)
            ->whereNotNull('paid_out_at')
            ->whereDate('paid_out_at', '>=', (string) $filters['from'])
            ->whereDate('paid_out_at', '<=', (string) $filters['to']);

        if (array_key_exists('admin_user_id', $filters)) {
            $base->where('paid_out_by_user_id', (int) $filters['admin_user_id']);
        }

        $summary = (clone $base)
            ->selectRaw(
                'COUNT(*) as payout_count,
                COALESCE(SUM(total_amount), 0) as paid_out_total_amount,
                COALESCE(AVG(total_amount), 0) as avg_payout_per_bet'
            )
            ->first();

        $byAdmin = (clone $base)
            ->leftJoin('users', 'users.id', '=', 'bets.paid_out_by_user_id')
            ->selectRaw(
                'bets.paid_out_by_user_id as admin_user_id,
                users.name as admin_name,
                COUNT(*) as payout_count,
                COALESCE(SUM(bets.total_amount), 0) as paid_out_total_amount'
            )
            ->groupBy('bets.paid_out_by_user_id', 'users.name')
            ->orderByDesc('payout_count')
            ->get()
            ->map(fn ($row): array => [
                'admin_user_id' => $row->admin_user_id === null ? null : (int) $row->admin_user_id,
                'admin_name' => $row->admin_name,
                'payout_count' => (int) $row->payout_count,
                'paid_out_total_amount' => $this->toMoney($row->paid_out_total_amount),
            ])
            ->values()
            ->all();

        $timeline = (clone $base)
            ->selectRaw(
                'DATE(paid_out_at) as date,
                COUNT(*) as payout_count,
                COALESCE(SUM(total_amount), 0) as paid_out_total_amount'
            )
            ->groupBy(DB::raw('DATE(paid_out_at)'))
            ->orderBy(DB::raw('DATE(paid_out_at)'))
            ->get()
            ->map(fn ($row): array => [
                'date' => (string) $row->date,
                'payout_count' => (int) $row->payout_count,
                'paid_out_total_amount' => $this->toMoney($row->paid_out_total_amount),
            ])
            ->values()
            ->all();

        return [
            'payout_count' => (int) ($summary->payout_count ?? 0),
            'paid_out_total_amount' => $this->toMoney($summary->paid_out_total_amount ?? 0),
            'avg_payout_per_bet' => $this->toMoney($summary->avg_payout_per_bet ?? 0),
            'by_admin' => $byAdmin,
            'daily_timeline' => $timeline,
        ];
    }

    public function topNumbers(array $filters): array
    {
        $limit = (int) ($filters['limit'] ?? 20);

        $query = DB::table('bet_numbers')
            ->join('bets', 'bets.id', '=', 'bet_numbers.bet_id');

        $this->applyBetDateFilters($query, $filters);

        if (array_key_exists('bet_type', $filters)) {
            $query->where('bets.bet_type', (string) $filters['bet_type']);
        }

        return $query
            ->selectRaw(
                'bet_numbers.number as number,
                COUNT(*) as bet_frequency,
                COUNT(DISTINCT bets.user_id) as distinct_user_count,
                COALESCE(SUM(bets.amount), 0) as total_stake'
            )
            ->groupBy('bet_numbers.number')
            ->orderByDesc('bet_frequency')
            ->orderBy('bet_numbers.number')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'number' => (int) $row->number,
                'bet_frequency' => (int) $row->bet_frequency,
                'distinct_user_count' => (int) $row->distinct_user_count,
                'total_stake' => $this->toMoney($row->total_stake),
            ])
            ->values()
            ->all();
    }

    public function settlementRuns(array $filters): array
    {
        $rows = DB::table('bet_settlement_runs')
            ->leftJoin('two_d_results', 'two_d_results.id', '=', 'bet_settlement_runs.two_d_result_id')
            ->whereDate(DB::raw('COALESCE(bet_settlement_runs.settled_at, bet_settlement_runs.created_at)'), '>=', (string) $filters['from'])
            ->whereDate(DB::raw('COALESCE(bet_settlement_runs.settled_at, bet_settlement_runs.created_at)'), '<=', (string) $filters['to'])
            ->select([
                'bet_settlement_runs.history_id',
                'bet_settlement_runs.settled_at',
                'bet_settlement_runs.summary',
                'bet_settlement_runs.created_at',
                'two_d_results.stock_date',
                'two_d_results.open_time',
                'two_d_results.twod',
            ])
            ->orderByDesc('bet_settlement_runs.created_at')
            ->get();

        $runs = [];
        $summaryTotals = [
            'processed' => 0,
            'won' => 0,
            'lost' => 0,
        ];

        foreach ($rows as $row) {
            $summary = $this->decodeSummary($row->summary);
            $summaryTotals['processed'] += (int) ($summary['processed'] ?? 0);
            $summaryTotals['won'] += (int) ($summary['won'] ?? 0);
            $summaryTotals['lost'] += (int) ($summary['lost'] ?? 0);

            $runs[] = [
                'history_id' => (string) $row->history_id,
                'stock_date' => $row->stock_date,
                'open_time' => $row->open_time,
                'twod' => $row->twod,
                'settled_at' => $row->settled_at,
                'created_at' => $row->created_at,
                'summary' => $summary,
            ];
        }

        return [
            'total_runs' => count($runs),
            'completed_runs' => count(array_filter($runs, static fn (array $run): bool => $run['settled_at'] !== null)),
            'pending_runs' => count(array_filter($runs, static fn (array $run): bool => $run['settled_at'] === null)),
            'summary_totals' => $summaryTotals,
            'runs' => $runs,
        ];
    }

    private function statusBuckets(Builder $query, string $column, int $total): array
    {
        return $query
            ->selectRaw($column.' as value, COUNT(*) as count')
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->map(fn ($row): array => [
                'value' => (string) $row->value,
                'count' => (int) $row->count,
                'percentage' => $total > 0 ? round(((int) $row->count * 100) / $total, 2) : 0.0,
            ])
            ->values()
            ->all();
    }

    private function applyBetDateFilters(Builder $query, array $filters): void
    {
        $query
            ->whereDate('stock_date', '>=', (string) $filters['from'])
            ->whereDate('stock_date', '<=', (string) $filters['to']);
    }

    private function applyOptionalBetFilters(Builder $query, array $filters): void
    {
        if (array_key_exists('target_opentime', $filters)) {
            $query->where('target_opentime', (string) $filters['target_opentime']);
        }

        if (array_key_exists('bet_type', $filters)) {
            $query->where('bet_type', (string) $filters['bet_type']);
        }
    }

    private function decodeSummary(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function toMoney(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
