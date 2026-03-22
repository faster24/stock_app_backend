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

        $numbers = $attributes['bet_numbers'] ?? [];
        unset($attributes['bet_numbers']);

        $attributes['total_amount'] = $this->calculateTotalAmount(
            (int) ($attributes['amount'] ?? 0),
            count($numbers)
        );
        $attributes['stock_date'] = Carbon::now()->toDateString();

        $this->validateCreateBetNumbersForType((string) ($attributes['bet_type'] ?? ''), $numbers);

        $bet = DB::transaction(function () use ($userId, $attributes, $numbers): Bet {
            $bet = Bet::query()->create(array_merge($attributes, [
                'user_id' => $userId,
            ]));

            $this->replaceBetNumbers($bet, $numbers);

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
        $numbers = $attributes['bet_numbers'] ?? [];
        unset($attributes['bet_numbers'], $attributes['user_id']);

        $resolvedAmount = array_key_exists('amount', $attributes)
            ? (int) $attributes['amount']
            : (int) $bet->amount;
        $resolvedBetCount = $hasBetNumbers
            ? count($numbers)
            : $bet->betNumbers()->count();
        $attributes['total_amount'] = $this->calculateTotalAmount($resolvedAmount, $resolvedBetCount);

        return DB::transaction(function () use ($bet, $attributes, $hasBetNumbers, $numbers): Bet {
            $bet->update($attributes);

            if ($hasBetNumbers) {
                $this->replaceBetNumbers($bet, $numbers);
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

    private function validateCreateBetNumbersForType(string $betType, array $numbers): void
    {
        $min = null;
        $max = null;

        if ($betType === BetType::TWO_D->value) {
            $min = 10;
            $max = 99;
        }

        if ($betType === BetType::THREE_D->value) {
            $min = 100;
            $max = 999;
        }

        if ($min === null || $max === null) {
            return;
        }

        foreach (array_values($numbers) as $index => $number) {
            $resolvedNumber = (int) $number;

            if ($resolvedNumber >= $min && $resolvedNumber <= $max) {
                continue;
            }

            throw ValidationException::withMessages([
                'bet_numbers.'.$index => [
                    'The bet number must be between '.$min.' and '.$max.' when bet type is '.$betType.'.',
                ],
            ]);
        }
    }

    private function calculateTotalAmount(int $amount, int $betNumberCount): string
    {
        $total = max(0, $amount) * max(0, $betNumberCount);

        return number_format($total, 2, '.', '');
    }
}
