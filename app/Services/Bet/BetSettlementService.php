<?php

namespace App\Services\Bet;

use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Models\ThreeDResult;
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

        if (! $this->beginSettlementRun($historyId, BetType::TWO_D, (int) $result->getKey(), null)) {
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

        return $this->settleEligibleBets(
            $historyId,
            BetType::TWO_D,
            (int) $result->getKey(),
            null,
            $resolvedChunkSize,
            $eligibleBaseQuery,
            (clone $scopeQuery)->count(),
            $winningNumber,
            $settledAt
        );
    }

    public function settleThreeDResult(ThreeDResult $result, int $chunkSize = 500): array
    {
        $resolvedStockDate = $this->resolveStockDate($result->stock_date);
        $winningNumber = $this->resolveWinningNumber($result->threed);

        if ($resolvedStockDate === null || $winningNumber === null) {
            return self::NOOP_SUMMARY;
        }

        $historyId = '3d-result-'.$resolvedStockDate;

        if (! $this->beginSettlementRun($historyId, BetType::THREE_D, null, (int) $result->getKey())) {
            return self::NOOP_SUMMARY;
        }

        $resolvedChunkSize = max(1, min(2000, $chunkSize));
        $settledAt = Carbon::now();

        $scopeQuery = Bet::query()
            ->where('bet_type', BetType::THREE_D->value)
            ->whereDate('stock_date', $resolvedStockDate);

        $eligibleBaseQuery = (clone $scopeQuery)
            ->where('status', BetStatus::ACCEPTED->value)
            ->where('bet_result_status', BetResultStatus::OPEN->value);

        return $this->settleEligibleBets(
            $historyId,
            BetType::THREE_D,
            null,
            (int) $result->getKey(),
            $resolvedChunkSize,
            $eligibleBaseQuery,
            (clone $scopeQuery)->count(),
            $winningNumber,
            $settledAt
        );
    }

    private function settleEligibleBets(
        string $historyId,
        BetType $betType,
        ?int $twoDResultId,
        ?int $threeDResultId,
        int $chunkSize,
        $eligibleBaseQuery,
        int $totalInScope,
        int $winningNumber,
        Carbon $settledAt
    ): array {
        $summary = self::NOOP_SUMMARY;

        try {
            (clone $eligibleBaseQuery)
                ->select('id')
                ->orderBy('id')
                ->chunkById($chunkSize, function ($bets) use (
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

            $this->completeSettlementRun($historyId, $betType, $twoDResultId, $threeDResultId, $summary, $settledAt);

            return $summary;
        } catch (Throwable $throwable) {
            $this->rollbackSettlementRun($historyId);

            throw $throwable;
        }
    }

    private function beginSettlementRun(
        string $historyId,
        BetType $betType,
        ?int $twoDResultId,
        ?int $threeDResultId
    ): bool {
        try {
            DB::table('bet_settlement_runs')->insert([
                'history_id' => $historyId,
                'bet_type' => $betType->value,
                'two_d_result_id' => $twoDResultId,
                'three_d_result_id' => $threeDResultId,
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

    private function completeSettlementRun(
        string $historyId,
        BetType $betType,
        ?int $twoDResultId,
        ?int $threeDResultId,
        array $summary,
        Carbon $settledAt
    ): void {
        DB::table('bet_settlement_runs')
            ->where('history_id', $historyId)
            ->update([
                'bet_type' => $betType->value,
                'two_d_result_id' => $twoDResultId,
                'three_d_result_id' => $threeDResultId,
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

    private function resolveWinningNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= 999 ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || preg_match('/^\d+$/', $trimmed) !== 1) {
            return null;
        }

        $resolved = (int) $trimmed;

        return $resolved >= 1 && $resolved <= 999 ? $resolved : null;
    }
}
