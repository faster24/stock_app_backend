<?php

namespace App\Services\BettingDistribution;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\OddSetting;
use App\Services\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Distribution board for 3D bets.
 *
 * 3D has no session slots — bets carry a NULL target_opentime and a draw spans
 * several days — so this cannot reuse BettingDistributionService's period matrix.
 * Volume is aggregated over the currently open draw window (mirroring the bounds
 * BetSettlementService settles on), and number controls and temporary odds are
 * read at that draw's anchor — see ThreeDDrawScope — so a break holds until the
 * result is entered instead of lapsing at midnight.
 */
class ThreeDDistributionService extends Service
{
    /** The 3D sentinel for number_controls / temporary_odd_adjustments.target_opentime. */
    private const OPENTIME_SENTINEL = ThreeDDrawScope::OPENTIME_SENTINEL;

    private const MAX_NUMBER = 999;

    public function __construct(private readonly ThreeDDrawScope $drawScope) {}

    /**
     * Bets from the latest 3D result date onward belong to the open draw.
     *
     * The lower bound is inclusive to match BetSettlementService: a bet placed on a
     * result day belongs to the next draw, and excluding it there would orphan it.
     *
     * @return array{from: ?string, to: string}
     */
    public function resolveDrawWindow(): array
    {
        return [
            'from' => $this->drawScope->windowStart(),
            'to' => Carbon::now('Asia/Bangkok')->toDateString(),
        ];
    }

    public function getCurrentDrawDistribution(string $currency = 'THB'): array
    {
        $betType = BetType::THREE_D->value;
        $window = $this->resolveDrawWindow();
        // Controls and temporary odds hold for the whole draw, so they are read
        // at its anchor — not at today's date, which would expire them nightly.
        $controlsDate = $this->drawScope->anchorDate();

        $rows = DB::table('bet_numbers')
            ->join('bets', 'bets.id', '=', 'bet_numbers.bet_id')
            ->where('bets.bet_type', $betType)
            ->where('bets.currency', $currency)
            ->whereIn('bets.status', [BetStatus::PENDING->value, BetStatus::ACCEPTED->value])
            ->when(
                $window['from'] !== null,
                fn ($q) => $q->whereDate('bets.stock_date', '>=', $window['from']),
            )
            ->select([
                'bet_numbers.number',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('COALESCE(SUM(bet_numbers.amount), 0) as vol'),
            ])
            ->groupBy('bet_numbers.number')
            ->get();

        $baseOdd = $this->resolveBaseOdd($betType, $currency);

        $tempOddMap = DB::table('temporary_odd_adjustments')
            ->where('stock_date', $controlsDate)
            ->where('target_opentime', self::OPENTIME_SENTINEL)
            ->where('bet_type', $betType)
            ->where('currency', $currency)
            ->pluck('adjusted_odd', 'number')
            ->map(fn ($odd): string => number_format((float) $odd, 2, '.', ''))
            ->all();

        $controlMap = [];
        $controlRows = DB::table('number_controls')
            ->where('stock_date', $controlsDate)
            ->where('target_opentime', self::OPENTIME_SENTINEL)
            ->where('bet_type', $betType)
            ->where('currency', $currency)
            ->select(['number', 'is_closed', 'sales_limit'])
            ->get();

        foreach ($controlRows as $row) {
            $controlMap[(int) $row->number] = [
                'is_closed' => (bool) $row->is_closed,
                'sales_limit' => $row->sales_limit !== null
                    ? number_format((float) $row->sales_limit, 2, '.', '')
                    : null,
            ];
        }

        // Sparse: 1000 rows per poll is wasteful, so only numbers that carry
        // volume, a control or a temporary odd are emitted. The dashboard fills
        // the untouched numbers in with a default cell.
        $volumes = [];
        $counts = [];
        foreach ($rows as $row) {
            $number = (int) $row->number;
            if ($number < 0 || $number > self::MAX_NUMBER) {
                continue;
            }

            $volumes[$number] = (float) $row->vol;
            $counts[$number] = (int) $row->cnt;
        }

        $numbers = array_unique(array_merge(
            array_keys($volumes),
            array_map(intval(...), array_keys($tempOddMap)),
            array_keys($controlMap),
        ));

        $items = [];
        $totalCount = 0;
        $totalVolume = 0.0;

        foreach ($numbers as $number) {
            if ($number < 0 || $number > self::MAX_NUMBER) {
                continue;
            }

            $volume = $volumes[$number] ?? 0.0;
            $count = $counts[$number] ?? 0;
            $control = $controlMap[$number] ?? null;
            $salesLimit = $control['sales_limit'] ?? null;

            $totalCount += $count;
            $totalVolume += $volume;

            $items[] = [
                'number' => $number,
                'count' => $count,
                'volume' => number_format($volume, 2, '.', ''),
                'odd' => $tempOddMap[$number] ?? $baseOdd,
                'is_closed' => $control['is_closed'] ?? false,
                'sales_limit' => $salesLimit,
                'remaining' => $salesLimit !== null
                    ? number_format(max(0, (float) $salesLimit - $volume), 2, '.', '')
                    : null,
                'has_adjustment' => isset($tempOddMap[$number]),
                'has_control' => $control !== null,
            ];
        }

        usort($items, fn (array $a, array $b): int => ((float) $b['volume']) <=> ((float) $a['volume']));

        return [
            'draw_window' => $window,
            'controls_anchor_date' => $controlsDate,
            'latest_result_date' => $window['from'],
            'base_odd' => $baseOdd,
            'items' => $items,
            'summary' => [
                'total_active_bets' => $totalCount,
                'total_bet_volume' => number_format($totalVolume, 2, '.', ''),
            ],
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
