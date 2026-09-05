<?php

namespace App\Services\Agent;

use App\Enums\BetStatus;
use App\Services\Service;
use Illuminate\Support\Facades\DB;

/**
 * Agent commission report (daily / weekly / monthly buckets on stock_date).
 *
 * Commission is snapshotted onto each bet at placement (bets.agent_commission_rate
 * and bets.agent_commission), so changing an agent's rate never moves a figure that
 * has already been reported. This service only sums what is already stored.
 *
 * Buckets follow bets.stock_date, which BetService derives from the APP timezone
 * (UTC), not Asia/Yangon. A bet placed just after midnight Yangon therefore lands on
 * the previous UTC day. Every other report in this app (AnalyticsService,
 * BetReportService) behaves the same way, so commission totals stay reconcilable
 * against the bet reports. Changing the day boundary is a platform-wide change to
 * stock_date, not a change here.
 *
 * Rejected and refunded bets are excluded: their stake is returned to the player in
 * full, so no commission was ever earned on it.
 */
class AgentCommissionService extends Service
{
    private const EXCLUDED_STATUSES = [
        BetStatus::REJECTED,
        BetStatus::REFUNDED,
    ];

    public function report(array $filters): array
    {
        $rows = $this->fetchRows($filters);

        $mapped = [];
        $betsCount = 0;
        $totalStake = 0.0;
        $commission = 0.0;

        foreach ($rows as $row) {
            $mapped[] = [
                'bucket' => (string) $row->bucket,
                'agent_id' => (string) $row->agent_id,
                'agent_username' => $row->agent_username,
                'commission_rate' => $this->effectiveRate($row->total_stake, $row->commission),
                'bets_count' => (int) $row->bets_count,
                'total_stake' => $this->toMoney($row->total_stake),
                'commission' => $this->toMoney($row->commission),
            ];

            $betsCount += (int) $row->bets_count;
            $totalStake += (float) $row->total_stake;
            $commission += (float) $row->commission;
        }

        return [
            'summary' => [
                'bets_count' => $betsCount,
                'total_stake' => $this->toMoney($totalStake),
                'commission' => $this->toMoney($commission),
                'commission_rate' => $this->effectiveRate($totalStake, $commission),
            ],
            'rows' => $mapped,
        ];
    }

    public function csvRows(array $filters): array
    {
        return array_map(static fn (array $row): array => [
            $row['bucket'],
            $row['agent_username'],
            $row['bets_count'],
            $row['total_stake'],
            $row['commission_rate'],
            $row['commission'],
        ], $this->report($filters)['rows']);
    }

    public function csvHeader(): array
    {
        return ['Period', 'Agent', 'Bets', 'Stake', 'Rate %', 'Commission'];
    }

    private function fetchRows(array $filters): iterable
    {
        $bucketExpr = match ((string) $filters['granularity']) {
            'daily' => "DATE_FORMAT(b.stock_date, '%Y-%m-%d')",
            // ISO week — %x pairs with %v, so the year belongs to the week, not the date.
            'weekly' => "DATE_FORMAT(b.stock_date, '%x-W%v')",
            'monthly' => "DATE_FORMAT(b.stock_date, '%Y-%m')",
        };

        $query = DB::table('bets as b')
            ->join('users as u', 'u.id', '=', 'b.user_id')
            ->whereNotNull('b.agent_commission')
            ->whereNotIn('b.status', array_map(
                static fn (BetStatus $status): string => $status->value,
                self::EXCLUDED_STATUSES
            ))
            ->whereDate('b.stock_date', '>=', (string) $filters['from'])
            ->whereDate('b.stock_date', '<=', (string) $filters['to']);

        if (array_key_exists('agent_id', $filters)) {
            $query->where('b.user_id', (string) $filters['agent_id']);
        }

        return $query
            ->groupByRaw("{$bucketExpr}, u.id, u.username")
            ->orderByRaw("{$bucketExpr} asc, u.username asc")
            ->selectRaw(implode(', ', [
                "{$bucketExpr} as bucket",
                'u.id as agent_id',
                'u.username as agent_username',
                'COUNT(*) as bets_count',
                'COALESCE(SUM(b.total_amount), 0) as total_stake',
                'COALESCE(SUM(b.agent_commission), 0) as commission',
            ]))
            ->get();
    }

    /**
     * The rate an agent actually earned over the bucket. It equals the configured
     * rate whenever that rate held for every bet in the bucket, and lands between
     * the old and the new one when the admin changed it mid-period — which keeps
     * one row per agent per period rather than splitting the period per rate.
     */
    private function effectiveRate(mixed $totalStake, mixed $commission): string
    {
        $stake = (float) $totalStake;

        if ($stake <= 0.0) {
            return '0.00';
        }

        return $this->toMoney((float) $commission / $stake * 100);
    }

    private function toMoney(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
