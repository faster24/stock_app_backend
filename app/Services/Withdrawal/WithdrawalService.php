<?php

namespace App\Services\Withdrawal;

use App\Enums\Currency;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Enums\WithdrawalStatus;
use App\Events\WithdrawalCompletedEvent;
use App\Events\WithdrawalRejectedEvent;
use App\Events\WithdrawalRequestedEvent;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Service;
use App\Services\Wallet\WalletMutator;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawalService extends Service
{
    public function __construct(private WalletMutator $walletMutator) {}

    public function createForUser(string $userId, array $validated): Withdrawal
    {
        return DB::transaction(function () use ($userId, $validated) {
            $this->assertWalletCurrencyMatches($userId, Currency::from($validated['currency']));
            $this->assertUserHasCompleteBankInfo($userId);

            $hasPending = Withdrawal::query()
                ->where('user_id', $userId)
                ->where('status', WithdrawalStatus::PENDING->value)
                ->lockForUpdate()
                ->exists();

            if ($hasPending) {
                throw new DomainException('You already have a pending withdrawal request.');
            }

            $wallet = Wallet::query()->where('user_id', $userId)->first();

            $withdrawal = Withdrawal::create([
                'user_id'       => $userId,
                'currency'      => $validated['currency'],
                'amount'        => $validated['amount'],
                'status'        => WithdrawalStatus::PENDING->value,
                'bank_snapshot' => [
                    'bank_name'      => $wallet->bank_name?->value,
                    'account_name'   => $wallet->account_name,
                    'account_number' => $wallet->account_number,
                ],
            ]);

            $this->walletMutator->mutate(
                userId: $userId,
                type: WalletTransactionType::WITHDRAWAL,
                direction: WalletTransactionDirection::DEBIT,
                amount: $validated['amount'],
                reference: $withdrawal,
                createdByUserId: $userId,
            );

            WithdrawalRequestedEvent::dispatch($withdrawal);

            return $withdrawal->refresh();
        });
    }

    public function listForUser(string $userId, int $page, int $pageSize): LengthAwarePaginator
    {
        return Withdrawal::query()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    public function showForUser(string $userId, string $withdrawalId): ?Withdrawal
    {
        return Withdrawal::query()
            ->where('user_id', $userId)
            ->where('id', $withdrawalId)
            ->first();
    }

    public function listForAdmin(int $page, int $pageSize, ?WithdrawalStatus $status, ?string $userId = null): LengthAwarePaginator
    {
        $query = Withdrawal::query()
            ->with('user')
            ->orderBy('created_at', 'desc');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if ($userId !== null && $userId !== '') {
            $query->where('user_id', $userId);
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function complete(string $withdrawalId, string $adminUserId, UploadedFile $proofImage, ?string $adminNote): Withdrawal
    {
        return DB::transaction(function () use ($withdrawalId, $adminUserId, $proofImage, $adminNote) {
            $withdrawal = Withdrawal::query()
                ->where('id', $withdrawalId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->status !== WithdrawalStatus::PENDING) {
                throw new DomainException('Only PENDING withdrawals can be completed.');
            }

            $withdrawal->update([
                'status'              => WithdrawalStatus::COMPLETED->value,
                'admin_note'          => $adminNote,
                'reviewed_by_user_id' => $adminUserId,
                'reviewed_at'         => now(),
            ]);

            $withdrawal->addMedia($proofImage)->toMediaCollection('payout_proof');

            WithdrawalCompletedEvent::dispatch($withdrawal->refresh());

            return $withdrawal->refresh();
        });
    }

    public function reject(string $withdrawalId, string $adminUserId, string $rejectionReason): Withdrawal
    {
        return DB::transaction(function () use ($withdrawalId, $adminUserId, $rejectionReason) {
            $withdrawal = Withdrawal::query()
                ->where('id', $withdrawalId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->status !== WithdrawalStatus::PENDING) {
                throw new DomainException('Only PENDING withdrawals can be rejected.');
            }

            $withdrawal->update([
                'status'              => WithdrawalStatus::REJECTED->value,
                'rejection_reason'    => $rejectionReason,
                'reviewed_by_user_id' => $adminUserId,
                'reviewed_at'         => now(),
            ]);

            $this->walletMutator->mutate(
                userId: $withdrawal->user_id,
                type: WalletTransactionType::WITHDRAWAL_REFUND,
                direction: WalletTransactionDirection::CREDIT,
                amount: $withdrawal->amount,
                reference: $withdrawal->fresh(),
                createdByUserId: $adminUserId,
                note: $rejectionReason,
            );

            WithdrawalRejectedEvent::dispatch($withdrawal->refresh());

            return $withdrawal->refresh();
        });
    }

    public function cancelForUser(string $userId, string $withdrawalId): Withdrawal
    {
        return DB::transaction(function () use ($userId, $withdrawalId) {
            $withdrawal = Withdrawal::query()
                ->where('id', $withdrawalId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($withdrawal === null) {
                abort(404, 'Withdrawal not found.');
            }

            if ($withdrawal->status !== WithdrawalStatus::PENDING) {
                throw new DomainException('Only PENDING withdrawals can be cancelled.');
            }

            $withdrawal->update([
                'status'              => WithdrawalStatus::REJECTED->value,
                'rejection_reason'    => 'Cancelled by user.',
                'reviewed_by_user_id' => $userId,
                'reviewed_at'         => now(),
            ]);

            $this->walletMutator->mutate(
                userId: $userId,
                type: WalletTransactionType::WITHDRAWAL_REFUND,
                direction: WalletTransactionDirection::CREDIT,
                amount: $withdrawal->amount,
                reference: $withdrawal->fresh(),
                createdByUserId: $userId,
                note: 'Cancelled by user.',
            );

            return $withdrawal->refresh();
        });
    }

    private function assertWalletCurrencyMatches(string $userId, Currency $requested): void
    {
        $wallet = Wallet::query()->where('user_id', $userId)->first();

        if ($wallet === null || $wallet->currency === null) {
            throw ValidationException::withMessages([
                'wallet_currency' => ['Please set up your wallet currency before making a withdrawal.'],
            ]);
        }

        if ($wallet->currency !== $requested) {
            throw ValidationException::withMessages([
                'currency' => ['Currency must match your wallet currency.'],
            ]);
        }
    }

    private function assertUserHasCompleteBankInfo(string $userId): void
    {
        $wallet = Wallet::query()->where('user_id', $userId)->first();

        if ($wallet === null
            || blank($wallet->bank_name)
            || blank($wallet->account_name)
            || blank($wallet->account_number)) {
            throw ValidationException::withMessages([
                'bank_info' => ['Bank account information is required before creating a withdrawal.'],
            ]);
        }
    }
}
