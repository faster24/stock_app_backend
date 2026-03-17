<?php

namespace App\Services\Bet;

use App\Models\Bet;
use App\Services\Service;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class BetService extends Service
{
    public const DELETE_RESULT_NOT_FOUND = 'not_found';

    public const DELETE_RESULT_DELETED = 'deleted';

    public const DELETE_RESULT_CONFLICT = 'conflict';

    public function listForUser(int $userId, int $page = 1, int $pageSize = 10): Collection
    {
        $resolvedPage = max(1, $page);
        $resolvedPageSize = min(100, max(1, $pageSize));

        return Bet::query()
            ->with('betNumbers')
            ->where('user_id', $userId)
            ->latest()
            ->forPage($resolvedPage, $resolvedPageSize)
            ->get();
    }

    public function showForUser(int $userId, int $betId): ?Bet
    {
        return Bet::query()
            ->with('betNumbers')
            ->where('user_id', $userId)
            ->whereKey($betId)
            ->first();
    }

    public function createForUser(int $userId, array $attributes): Bet
    {
        $numbers = $attributes['bet_numbers'] ?? [];
        unset($attributes['bet_numbers']);

        return DB::transaction(function () use ($userId, $attributes, $numbers): Bet {
            $bet = Bet::query()->create(array_merge($attributes, [
                'user_id' => $userId,
            ]));

            $this->replaceBetNumbers($bet, $numbers);

            return $bet->fresh(['betNumbers']);
        });
    }

    public function updateForUser(int $userId, int $betId, array $attributes): ?Bet
    {
        $bet = Bet::query()
            ->where('user_id', $userId)
            ->whereKey($betId)
            ->first();

        if ($bet === null) {
            return null;
        }

        $hasBetNumbers = array_key_exists('bet_numbers', $attributes);
        $numbers = $attributes['bet_numbers'] ?? [];
        unset($attributes['bet_numbers'], $attributes['user_id']);

        return DB::transaction(function () use ($bet, $attributes, $hasBetNumbers, $numbers): Bet {
            $bet->update($attributes);

            if ($hasBetNumbers) {
                $this->replaceBetNumbers($bet, $numbers);
            }

            return $bet->fresh(['betNumbers']);
        });
    }

    public function deleteForUser(int $userId, int $betId): string
    {
        $bet = Bet::query()
            ->where('user_id', $userId)
            ->whereKey($betId)
            ->first();

        if ($bet === null) {
            return self::DELETE_RESULT_NOT_FOUND;
        }

        try {
            $bet->delete();
        } catch (QueryException $exception) {
            if ($this->isDeleteRestrictionConflict($exception)) {
                return self::DELETE_RESULT_CONFLICT;
            }

            throw $exception;
        }

        return self::DELETE_RESULT_DELETED;
    }

    private function isDeleteRestrictionConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return $sqlState === '23000' && $driverCode === '1451';
    }

    private function replaceBetNumbers(Bet $bet, array $numbers): void
    {
        $bet->betNumbers()->delete();

        if ($numbers === []) {
            return;
        }

        $rows = array_map(
            static fn (int|string $number): array => ['number' => (int) $number],
            array_values($numbers)
        );

        $bet->betNumbers()->createMany($rows);
    }
}
