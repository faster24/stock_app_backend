<?php

namespace App\Services\Deposit;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Events\DepositApprovedEvent;
use App\Events\DepositRejectedEvent;
use App\Models\Deposit;
use App\Models\Wallet;
use App\Services\Service;
use App\Services\Wallet\WalletMutator;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepositService extends Service
{
    public function __construct(private WalletMutator $walletMutator) {}

    public function createForUser(string $userId, array $validated, UploadedFile $proofImage): Deposit
    {
        $this->assertWalletCurrencyMatches($userId, Currency::from($validated['currency']));

        $deposit = Deposit::create([
            'user_id' => $userId,
            'admin_bank_setting_id' => $validated['admin_bank_setting_id'],
            'currency' => $validated['currency'],
            'claimed_amount' => $validated['claimed_amount'],
            'transfer_note' => $validated['transfer_note'] ?? null,
            'status' => DepositStatus::PENDING->value,
        ]);

        $deposit->addMedia($proofImage)->toMediaCollection('proof_of_payment');

        return $deposit->refresh();
    }

    public function listForUser(string $userId, int $page, int $pageSize): LengthAwarePaginator
    {
        return Deposit::query()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    public function showForUser(string $userId, string $depositId): ?Deposit
    {
        return Deposit::query()
            ->where('user_id', $userId)
            ->where('id', $depositId)
            ->first();
    }

    public function listForAdmin(int $page, int $pageSize, ?DepositStatus $status, ?string $userId = null): LengthAwarePaginator
    {
        $query = Deposit::query()
            ->with(['user', 'adminBankSetting', 'media'])
            ->orderBy('created_at', 'desc');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if ($userId !== null && $userId !== '') {
            $query->where('user_id', $userId);
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function approve(string $depositId, string $adminUserId, ?int $approvedAmount, ?string $adminNote): Deposit
    {
        return DB::transaction(function () use ($depositId, $adminUserId, $approvedAmount, $adminNote) {
            $deposit = Deposit::query()
                ->where('id', $depositId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($deposit->status !== DepositStatus::PENDING) {
                throw new DomainException('Only PENDING deposits can be approved.');
            }

            $finalAmount = $approvedAmount ?? $deposit->claimed_amount;

            if ($finalAmount !== $deposit->claimed_amount && $adminNote === null) {
                throw ValidationException::withMessages([
                    'admin_note' => ['Admin note is required when approving a partial amount.'],
                ]);
            }

            $deposit->update([
                'status' => DepositStatus::APPROVED->value,
                'approved_amount' => $finalAmount,
                'admin_note' => $adminNote,
                'reviewed_by_user_id' => $adminUserId,
                'reviewed_at' => now(),
            ]);

            $this->walletMutator->mutate(
                userId: $deposit->user_id,
                type: WalletTransactionType::DEPOSIT,
                direction: WalletTransactionDirection::CREDIT,
                amount: $finalAmount,
                reference: $deposit->fresh(),
                createdByUserId: $adminUserId,
                note: $adminNote,
            );

            DepositApprovedEvent::dispatch($deposit);

            return $deposit->refresh();
        });
    }

    public function reject(string $depositId, string $adminUserId, string $rejectionReason): Deposit
    {
        return DB::transaction(function () use ($depositId, $adminUserId, $rejectionReason) {
            $deposit = Deposit::query()
                ->where('id', $depositId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($deposit->status !== DepositStatus::PENDING) {
                throw new DomainException('Only PENDING deposits can be rejected.');
            }

            $deposit->update([
                'status' => DepositStatus::REJECTED->value,
                'rejection_reason' => $rejectionReason,
                'reviewed_by_user_id' => $adminUserId,
                'reviewed_at' => now(),
            ]);

            DepositRejectedEvent::dispatch($deposit);

            return $deposit->refresh();
        });
    }

    public function cancelForUser(string $userId, string $depositId): Deposit
    {
        return DB::transaction(function () use ($userId, $depositId) {
            $deposit = Deposit::query()
                ->where('id', $depositId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($deposit === null) {
                abort(404, 'Deposit not found.');
            }

            if ($deposit->status !== DepositStatus::PENDING) {
                throw new DomainException('Only PENDING deposits can be cancelled.');
            }

            $deposit->update([
                'status' => DepositStatus::REJECTED->value,
                'rejection_reason' => 'Cancelled by user.',
                'reviewed_by_user_id' => $userId,
                'reviewed_at' => now(),
            ]);

            return $deposit->refresh();
        });
    }

    private function assertWalletCurrencyMatches(string $userId, Currency $requested): void
    {
        $wallet = Wallet::query()->where('user_id', $userId)->first();

        if ($wallet === null || $wallet->currency === null) {
            throw ValidationException::withMessages([
                'wallet_currency' => ['Please set up your wallet currency before making a deposit.'],
            ]);
        }

        if ($wallet->currency !== $requested) {
            throw ValidationException::withMessages([
                'currency' => ['Currency must match your wallet currency.'],
            ]);
        }
    }
}
