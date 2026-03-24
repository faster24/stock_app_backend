<?php

namespace App\Services\Bet;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Services\Service;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

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
            ->with(['betNumbers', 'media'])
            ->where('user_id', $userId)
            ->latest()
            ->forPage($resolvedPage, $resolvedPageSize)
            ->get();
    }

    public function listForAdmin(int $page = 1, int $pageSize = 10): Collection
    {
        $resolvedPage = max(1, $page);
        $resolvedPageSize = min(100, max(1, $pageSize));

        return Bet::query()
            ->with(['betNumbers', 'media'])
            ->latest()
            ->forPage($resolvedPage, $resolvedPageSize)
            ->get();
    }

    public function showForUser(int $userId, string $betId): ?Bet
    {
        return Bet::query()
            ->with(['betNumbers', 'media', 'user.wallet'])
            ->where('user_id', $userId)
            ->whereKey($betId)
            ->first();
    }

    public function createForUser(int $userId, array $attributes): Bet
    {
        $paySlipImage = $attributes['pay_slip_image'] ?? null;
        unset($attributes['pay_slip_image']);

        if (! $paySlipImage instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'pay_slip_image' => ['The pay_slip_image field is required.'],
            ]);
        }

        $numberEntries = $this->normalizeBetNumberEntries(
            (string) ($attributes['bet_type'] ?? ''),
            $attributes['bet_numbers'] ?? [],
            array_key_exists('amount', $attributes) ? (int) $attributes['amount'] : null,
        );
        unset($attributes['bet_numbers']);

        $attributes['amount'] = $this->deriveLegacyAmount($numberEntries);
        $attributes['total_amount'] = $this->calculateTotalAmount($numberEntries);
        $attributes['stock_date'] = Carbon::now()->toDateString();

        $bet = DB::transaction(function () use ($userId, $attributes, $numberEntries): Bet {
            $bet = Bet::query()->create(array_merge($attributes, [
                'user_id' => $userId,
            ]));

            $this->replaceBetNumbers($bet, $numberEntries);

            return $bet->fresh(['betNumbers', 'media']);
        });

        try {
            $bet->addMedia($paySlipImage)->toMediaCollection('pay_slip');
        } catch (Throwable $throwable) {
            $bet->delete();

            throw ValidationException::withMessages([
                'pay_slip_image' => ['The pay slip image could not be saved.'],
            ]);
        }

        return $bet->fresh(['betNumbers', 'media']);
    }

    public function updateForUser(int $userId, string $betId, array $attributes): ?Bet
    {
        $bet = Bet::query()
            ->where('user_id', $userId)
            ->whereKey($betId)
            ->first();

        if ($bet === null) {
            return null;
        }

        $hasBetNumbers = array_key_exists('bet_numbers', $attributes);
        $numberEntries = $hasBetNumbers
            ? $this->normalizeBetNumberEntries(
                (string) ($attributes['bet_type'] ?? $bet->bet_type->value),
                $attributes['bet_numbers'] ?? [],
                array_key_exists('amount', $attributes) ? (int) $attributes['amount'] : null,
            )
            : [];
        unset($attributes['bet_numbers'], $attributes['user_id']);

        if ($hasBetNumbers) {
            $attributes['amount'] = $this->deriveLegacyAmount($numberEntries);
            $attributes['total_amount'] = $this->calculateTotalAmount($numberEntries);
        } else {
            $attributes['total_amount'] = $this->calculateTotalAmountFromStoredBetNumbers($bet);
        }

        return DB::transaction(function () use ($bet, $attributes, $hasBetNumbers, $numberEntries): Bet {
            $bet->update($attributes);

            if ($hasBetNumbers) {
                $this->replaceBetNumbers($bet, $numberEntries);
            }

            return $bet->fresh(['betNumbers']);
        });
    }

    public function deleteForUser(int $userId, string $betId): string
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

    public function updateReviewStatusForAdmin(string $betId, string $targetStatus): ?Bet
    {
        $bet = Bet::query()
            ->with(['betNumbers', 'media'])
            ->whereKey($betId)
            ->first();

        if ($bet === null) {
            return null;
        }

        $resolvedTarget = BetStatus::from($targetStatus);

        if ($resolvedTarget === BetStatus::REFUNDED) {
            if ($bet->payout_status !== BetPayoutStatus::PENDING) {
                throw new DomainException('Paid out bets cannot be refunded.');
            }
        } else {
            $policy = app(BetStatusTransitionPolicy::class);
            $policy->assertReviewTransitionAllowed($bet->status, $resolvedTarget);
        }

        $payload = [
            'status' => $resolvedTarget,
        ];

        if (in_array($resolvedTarget, [BetStatus::REJECTED, BetStatus::REFUNDED], true)) {
            $payload['bet_result_status'] = BetResultStatus::INVALID;
        }

        if ($resolvedTarget === BetStatus::REFUNDED) {
            $payload['payout_status'] = BetPayoutStatus::REFUNDED;
        }

        $bet->update($payload);

        return $bet->fresh(['betNumbers', 'media']);
    }

    private function isDeleteRestrictionConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return $sqlState === '23000' && $driverCode === '1451';
    }

    private function replaceBetNumbers(Bet $bet, array $numberEntries): void
    {
        $bet->betNumbers()->delete();

        if ($numberEntries === []) {
            return;
        }

        $rows = array_map(
            static fn (array $entry): array => [
                'number' => (int) $entry['number'],
                'amount' => (int) $entry['amount'],
            ],
            array_values($numberEntries)
        );

        $bet->betNumbers()->createMany($rows);
    }

    private function normalizeBetNumberEntries(string $betType, array $betNumbers, ?int $legacyAmount = null): array
    {
        $entries = [];
        $min = null;
        $max = null;

        if ($betType === BetType::TWO_D->value) {
            $min = 1;
            $max = 99;
        }

        if ($betType === BetType::THREE_D->value) {
            $min = 1;
            $max = 999;
        }

        if ($min === null || $max === null) {
            return $entries;
        }

        foreach (array_values($betNumbers) as $index => $entry) {
            $resolvedNumber = null;
            $resolvedAmount = null;

            if (is_array($entry)) {
                $resolvedNumber = $this->resolveInteger($entry['number'] ?? null);
                $resolvedAmount = $this->resolveInteger($entry['amount'] ?? null);
            } else {
                $resolvedNumber = $this->resolveInteger($entry);
                $resolvedAmount = $legacyAmount;
            }

            if (! is_int($resolvedNumber)) {
                throw ValidationException::withMessages([
                    'bet_numbers.'.$index.'.number' => ['The bet number must be an integer.'],
                ]);
            }

            if ($resolvedNumber >= $min && $resolvedNumber <= $max) {
                if (! is_int($resolvedAmount) || $resolvedAmount < 1) {
                    $amountKey = is_array($entry)
                        ? 'bet_numbers.'.$index.'.amount'
                        : 'amount';

                    throw ValidationException::withMessages([
                        $amountKey => ['The amount field must be at least 1.'],
                    ]);
                }

                $entries[] = [
                    'number' => $resolvedNumber,
                    'amount' => $resolvedAmount,
                ];

                continue;
            }

            throw ValidationException::withMessages([
                'bet_numbers.'.$index => [
                    'The bet number must be between '.$min.' and '.$max.' when bet type is '.$betType.'.',
                ],
            ]);
        }

        return $entries;
    }

    private function resolveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function calculateTotalAmount(array $numberEntries): string
    {
        $total = array_sum(array_map(
            static fn (array $entry): int => max(0, (int) $entry['amount']),
            $numberEntries
        ));

        return number_format($total, 2, '.', '');
    }

    private function calculateTotalAmountFromStoredBetNumbers(Bet $bet): string
    {
        $total = (int) $bet->betNumbers()->sum('amount');

        return number_format($total, 2, '.', '');
    }

    private function deriveLegacyAmount(array $numberEntries): int
    {
        if ($numberEntries === []) {
            return 0;
        }

        return (int) $numberEntries[0]['amount'];
    }
}
