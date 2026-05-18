<?php

namespace App\Services\BettingDistribution;

use App\Enums\BetStatus;
use App\Models\OddSetting;
use App\Services\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BettingDistributionService extends Service
{
    private const VALID_PERIODS = ['11:00:00', '12:01:00', '15:00:00', '16:30:00'];

    private const PERIOD_KEYS = [
        '11:00:00' => 'period_11_00',
        '12:01:00' => 'period_12_01',
        '15:00:00' => 'period_15_00',
        '16:30:00' => 'period_16_30',
    ];

    public function getCurrentPeriodDistribution(string $betType = '2D', string $currency = 'THB'): array
    {
        $now = Carbon::now('Asia/Bangkok');
        $activePeriod = $this->determineActivePeriod($now);

        return $this->getDistributionForDate($now->toDateString(), $activePeriod, $betType, $currency);
    }

    public function getDistributionForDate(string $date, string $focusPeriod, string $betType = '2D', string $currency = 'THB'): array
    {
        $rows = DB::table('bet_numbers')
            ->join('bets', 'bets.id', '=', 'bet_numbers.bet_id')
            ->whereDate('bets.stock_date', $date)
            ->whereIn('bets.status', [BetStatus::PENDING->value, BetStatus::ACCEPTED->value])
            ->select([
                'bet_numbers.number',
                'bets.target_opentime',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('COALESCE(SUM(bet_numbers.amount), 0) as vol'),
            ])
            ->groupBy('bet_numbers.number', 'bets.target_opentime')
            ->get();

        $baseOdd = $this->resolveBaseOdd($betType, $currency);

        // Build lookup: number → period → adjusted_odd
        $tempOdds = DB::table('temporary_odd_adjustments')
            ->where('stock_date', $date)
            ->where('bet_type', $betType)
            ->where('currency', $currency)
            ->select(['number', 'target_opentime', 'adjusted_odd'])
            ->get();

        $tempOddMap = [];
        foreach ($tempOdds as $row) {
            $tempOddMap[$row->number][$row->target_opentime] = number_format((float) $row->adjusted_odd, 2, '.', '');
        }

        $adjustedNumbers = array_keys($tempOddMap);

        $matrix = [];
        for ($i = 0; $i <= 99; $i++) {
            $periodData = [];
            foreach (self::VALID_PERIODS as $period) {
                $periodKey = self::PERIOD_KEYS[$period];
                $periodData[$periodKey] = [
                    'count' => 0,
                    'volume' => '0.00',
                    'odd' => $tempOddMap[$i][$period] ?? $baseOdd,
                ];
            }

            $matrix[$i] = array_merge(
                ['number' => $i],
                $periodData,
                [
                    'total_count' => 0,
                    'total_volume' => '0.00',
                    'has_adjustment' => in_array($i, $adjustedNumbers, true),
                ]
            );
        }

        foreach ($rows as $row) {
            $num = (int) $row->number;
            if ($num < 0 || $num > 99) {
                continue;
            }

            $key = self::PERIOD_KEYS[$row->target_opentime] ?? null;
            if ($key === null) {
                continue;
            }

            $matrix[$num][$key]['count'] = (int) $row->cnt;
            $matrix[$num][$key]['volume'] = number_format((float) $row->vol, 2, '.', '');
        }

        foreach ($matrix as &$item) {
            $totalCount = 0;
            $totalVolume = 0.0;
            foreach (self::PERIOD_KEYS as $periodKey) {
                $totalCount += $item[$periodKey]['count'];
                $totalVolume += (float) $item[$periodKey]['volume'];
            }
            $item['total_count'] = $totalCount;
            $item['total_volume'] = number_format($totalVolume, 2, '.', '');
        }
        unset($item);

        $distribution = array_values($matrix);

        $periodStatus = $this->determinePeriodStatus($date, $focusPeriod);
        $periodMeta = $this->buildPeriodMeta($date, $focusPeriod, $periodStatus);

        $summary = $this->buildSummary($distribution);

        return [
            'stock_date' => $date,
            'active_period' => $focusPeriod,
            'base_odd' => $baseOdd,
            'items' => $distribution,
            'current_period' => $periodMeta,
            'summary' => $summary,
        ];
    }

    public function getPeriodsForToday(): array
    {
        $now = Carbon::now('Asia/Bangkok');
        $today = $now->toDateString();

        $periodRows = DB::table('bet_numbers')
            ->join('bets', 'bets.id', '=', 'bet_numbers.bet_id')
            ->whereDate('bets.stock_date', $today)
            ->whereIn('bets.status', [BetStatus::PENDING->value, BetStatus::ACCEPTED->value])
            ->select([
                'bets.target_opentime',
                DB::raw('COUNT(DISTINCT bets.id) as bet_count'),
                DB::raw('COALESCE(SUM(bet_numbers.amount), 0) as total_vol'),
                DB::raw('bet_numbers.number as pop_number'),
            ])
            ->groupBy('bets.target_opentime')
            ->get()
            ->keyBy('target_opentime');

        $popularRows = DB::table('bet_numbers')
            ->join('bets', 'bets.id', '=', 'bet_numbers.bet_id')
            ->whereDate('bets.stock_date', $today)
            ->whereIn('bets.status', [BetStatus::PENDING->value, BetStatus::ACCEPTED->value])
            ->select([
                'bets.target_opentime',
                'bet_numbers.number',
                DB::raw('COALESCE(SUM(bet_numbers.amount), 0) as num_vol'),
            ])
            ->groupBy('bets.target_opentime', 'bet_numbers.number')
            ->orderByDesc('num_vol')
            ->get()
            ->groupBy('target_opentime')
            ->map(fn ($group) => $group->first());

        $tempOddCounts = DB::table('temporary_odd_adjustments')
            ->where('stock_date', $today)
            ->select(['target_opentime', DB::raw('COUNT(*) as cnt')])
            ->groupBy('target_opentime')
            ->get()
            ->keyBy('target_opentime');

        $betCountRows = DB::table('bets')
            ->whereDate('stock_date', $today)
            ->whereIn('status', [BetStatus::PENDING->value, BetStatus::ACCEPTED->value])
            ->select(['target_opentime', DB::raw('COUNT(*) as cnt')])
            ->groupBy('target_opentime')
            ->get()
            ->keyBy('target_opentime');

        $result = [];
        foreach (self::VALID_PERIODS as $period) {
            $status = $this->determinePeriodStatus($today, $period);
            $betCount = (int) ($betCountRows[$period]->cnt ?? 0);
            $totalVol = (float) ($periodRows[$period]->total_vol ?? 0);
            $tempCount = (int) ($tempOddCounts[$period]->cnt ?? 0);
            $mostPopular = $popularRows[$period] ?? null;

            $meta = $this->buildPeriodMeta($today, $period, $status);

            $result[] = array_merge($meta, [
                'total_bets' => $betCount,
                'total_volume' => number_format($totalVol, 2, '.', ''),
                'has_temp_odds' => $tempCount > 0,
                'temp_odds_count' => $tempCount,
                'most_popular_number' => $mostPopular ? (int) $mostPopular->number : null,
                'most_popular_volume' => $mostPopular
                    ? number_format((float) $mostPopular->num_vol, 2, '.', '')
                    : '0.00',
            ]);
        }

        return $result;
    }

    private function determineActivePeriod(Carbon $now): string
    {
        $h = $now->hour;
        $m = $now->minute;
        $totalMinutes = $h * 60 + $m;

        $slot11 = 11 * 60;       // 660
        $slot1201 = 12 * 60 + 1; // 721
        $slot15 = 15 * 60;       // 900
        $slot1630 = 16 * 60 + 30; // 990

        if ($totalMinutes < $slot11) {
            return '16:30:00';
        }

        if ($totalMinutes < $slot1201) {
            return '11:00:00';
        }

        if ($totalMinutes < $slot15) {
            return '12:01:00';
        }

        if ($totalMinutes < $slot1630) {
            return '15:00:00';
        }

        return '16:30:00';
    }

    private function determinePeriodStatus(string $date, string $period): string
    {
        $historyId = $this->deriveTwoDHistoryId($date, $period);
        if ($historyId !== null) {
            $settled = DB::table('bet_settlement_runs')
                ->where('history_id', $historyId)
                ->whereNotNull('settled_at')
                ->exists();

            if ($settled) {
                return 'settled';
            }
        }

        $now = Carbon::now('Asia/Bangkok');
        $activePeriod = $this->determineActivePeriod($now);
        $today = $now->toDateString();

        if ($date === $today && $activePeriod === $period) {
            return 'active';
        }

        return 'upcoming';
    }

    private function deriveTwoDHistoryId(string $date, string $period): ?string
    {
        $row = DB::table('two_d_results')
            ->whereDate('stock_date', $date)
            ->where('open_time', $period)
            ->select('history_id')
            ->first();

        if ($row === null) {
            return null;
        }

        return $row->history_id ?: null;
    }

    private function buildPeriodMeta(string $date, string $period, string $status): array
    {
        $periodStartTimes = [
            '11:00:00' => '11:00:00',
            '12:01:00' => '12:01:00',
            '15:00:00' => '15:00:00',
            '16:30:00' => '16:30:00',
        ];

        $periodEndTimes = [
            '11:00:00' => '12:00:00',
            '12:01:00' => '15:00:00',
            '15:00:00' => '16:30:00',
            '16:30:00' => '17:30:00',
        ];

        $startedAt = Carbon::parse("{$date} {$periodStartTimes[$period]}", 'Asia/Bangkok')
            ->toIso8601String();

        $endKey = $status === 'settled' ? 'ended_at' : 'expected_end_at';
        $endAt = Carbon::parse("{$date} {$periodEndTimes[$period]}", 'Asia/Bangkok')
            ->toIso8601String();

        return [
            'target_opentime' => $period,
            'stock_date' => $date,
            'period_status' => $status,
            'started_at' => $startedAt,
            $endKey => $endAt,
        ];
    }

    private function buildSummary(array $distribution): array
    {
        $totalCount = 0;
        $totalVolume = 0.0;
        foreach ($distribution as $row) {
            $totalCount += $row['total_count'];
            $totalVolume += (float) $row['total_volume'];
        }

        return [
            'total_active_bets' => $totalCount,
            'total_bet_volume' => number_format($totalVolume, 2, '.', ''),
        ];
    }

    private function resolveBaseOdd(string $betType, string $currency): string
    {
        $odd = OddSetting::query()
            ->where('bet_type', $betType)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->value('odd');

        return $odd !== null ? number_format((float) $odd, 2, '.', '') : '80.00';
    }
}
