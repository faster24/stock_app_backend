<?php

namespace App\Services\Bet;

use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Models\TwoDResult;
use App\Services\Service;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class BetSettlementService extends Service
{
    private const NOOP_SUMMARY = [
        'settled' => 0,
        'won' => 0,
        'lost' => 0,
        'skipped' => 0,
    ];

    public function settleTwoDResult(TwoDResult $result, int $chunkSize = 500): array
    {
        $historyId = $this->resolveHistoryId($result->history_id);
        $resolvedStockDate = $this->resolveStockDate($result->stock_date);
        $resolvedOpenTime = $this->resolveOpenTime($result->open_time);
        $winningNumber = $this->resolveWinningNumber($result->twod);

        if ($historyId === null) {
            throw new DomainException('Settlement history_id is required.');
        }

        if ($resolvedStockDate === null || $resolvedOpenTime === null || $winningNumber === null) {
            return self::NOOP_SUMMARY;
        }

        if (! $this->beginSettlementRun($historyId, $result)) {
            return self::NOOP_SUMMARY;
        }

        $resolvedChunkSize = max(1, min(2000, $chunkSize));
        $settledAt = Carbon::now();

        $scopeQuery = Bet::query()
            ->where('bet_type', BetType::TWO_D->value)
            ->whereDate('stock_date', $resolvedStockDate)
            ->where('target_opentime', $resolvedOpenTime);

        $eligibleBaseQuery = (clone $scopeQuery)
            ->where('status', BetStatus::ACCEPTED->value)
            ->where('bet_result_status', BetResultStatus::OPEN->value);

        $summary = self::NOOP_SUMMARY;

        $totalInScope = (clone $scopeQuery)->count();

        try {
            (clone $eligibleBaseQuery)
                ->select('id')
                ->orderBy('id')
                ->chunkById($resolvedChunkSize, function ($bets) use (
                    &$summary,
                    $winningNumber,
                    $historyId,
                    $settledAt
                ): void {
                    $betIds = $bets->pluck('id')->all();

                    if ($betIds === []) {
                        return;
                    }

                    $winnerIds = DB::table('bet_numbers')
                        ->whereIn('bet_id', $betIds)
                        ->where('number', $winningNumber)
                        ->distinct()
                        ->pluck('bet_id')
                        ->all();

                    $loserIds = array_values(array_diff($betIds, $winnerIds));

                    DB::transaction(function () use (&$summary, $winnerIds, $loserIds, $historyId, $settledAt): void {
                        if ($winnerIds !== []) {
                            $won = Bet::query()
                                ->whereIn('id', $winnerIds)
                                ->where('bet_result_status', BetResultStatus::OPEN->value)
                                ->update([
                                    'bet_result_status' => BetResultStatus::WON->value,
                                    'settled_at' => $settledAt,
                                    'settled_result_history_id' => $historyId,
                                    'updated_at' => $settledAt,
                                ]);

                            $summary['won'] += $won;
                            $summary['settled'] += $won;
                        }

                        if ($loserIds !== []) {
                            $lost = Bet::query()
                                ->whereIn('id', $loserIds)
                                ->where('bet_result_status', BetResultStatus::OPEN->value)
                                ->update([
                                    'bet_result_status' => BetResultStatus::LOST->value,
                                    'settled_at' => $settledAt,
                                    'settled_result_history_id' => $historyId,
                                    'updated_at' => $settledAt,
                                ]);

                            $summary['lost'] += $lost;
                            $summary['settled'] += $lost;
                        }
                    });
                });

            $summary['skipped'] = max(0, $totalInScope - $summary['settled']);

            $this->completeSettlementRun($historyId, $result, $summary, $settledAt);

            return $summary;
        } catch (Throwable $throwable) {
            $this->rollbackSettlementRun($historyId);

            throw $throwable;
        }
    }

    private function beginSettlementRun(string $historyId, TwoDResult $result): bool
    {
        try {
            DB::table('bet_settlement_runs')->insert([
                'history_id' => $historyId,
                'two_d_result_id' => $result->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            $driverCode = (string) ($exception->errorInfo[1] ?? '');

            if ($sqlState === '23000' && ($driverCode === '1062' || $driverCode === '19')) {
                return false;
            }

            throw $exception;
        }
    }

    private function completeSettlementRun(string $historyId, TwoDResult $result, array $summary, Carbon $settledAt): void
    {
        DB::table('bet_settlement_runs')
            ->where('history_id', $historyId)
            ->update([
                'two_d_result_id' => $result->getKey(),
                'settled_at' => $settledAt,
                'summary' => json_encode($summary, JSON_THROW_ON_ERROR),
                'updated_at' => $settledAt,
            ]);
    }

    private function rollbackSettlementRun(string $historyId): void
    {
        DB::table('bet_settlement_runs')
            ->where('history_id', $historyId)
            ->whereNull('settled_at')
            ->delete();
    }

    private function resolveHistoryId(mixed $historyId): ?string
    {
        if (! is_string($historyId)) {
            return null;
        }

        $trimmed = trim($historyId);

        return $trimmed === '' ? null : $trimmed;
    }

    private function resolveStockDate(mixed $stockDate): ?string
    {
        if ($stockDate === null || $stockDate === '') {
            return null;
        }

        if ($stockDate instanceof Carbon) {
            return $stockDate->toDateString();
        }

        if (! is_string($stockDate)) {
            return null;
        }

        $trimmed = trim($stockDate);

        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmed, $matches)) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function resolveOpenTime(mixed $openTime): ?string
    {
        if (! is_string($openTime)) {
            return null;
        }

        $trimmed = trim($openTime);

        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $trimmed, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        $second = array_key_exists(3, $matches) && $matches[3] !== ''
            ? (int) $matches[3]
            : 0;

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
            return null;
        }

        return $trimmed;
    }

    private function resolveWinningNumber(mixed $twoD): ?int
    {
        if (! is_numeric($twoD)) {
            return null;
        }

        return (int) $twoD;
    }
}
