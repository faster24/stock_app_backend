<?php

namespace App\Services\Wallet;

use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WalletMutator
{
    /**
     * Mutate a user's wallet balance atomically and append a ledger row.
     *
     * Opens its own DB transaction (or joins the caller's via savepoint).
     * Locks the wallet row FOR UPDATE to prevent concurrent balance races.
     *
     * @throws DomainException when currency is unset, amount invalid, or balance would go negative.
     */
    public function mutate(
        string $userId,
        WalletTransactionType $type,
        WalletTransactionDirection $direction,
        int $amount,
        ?Model $reference,
        string $createdByUserId,
        ?string $note = null,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new DomainException('Wallet mutation amount must be positive.');
        }

        return DB::transaction(function () use (
            $userId, $type, $direction, $amount, $reference, $createdByUserId, $note
        ) {
            $wallet = Wallet::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                throw new DomainException('Wallet not found.');
            }

            if ($wallet->currency === null) {
                throw new DomainException('Wallet currency is not set.');
            }

            $newBalance = $direction === WalletTransactionDirection::CREDIT
                ? $wallet->balance + $amount
                : $wallet->balance - $amount;

            if ($newBalance < 0) {
                throw new DomainException('Insufficient balance.');
            }

            $txn = WalletTransaction::create([
                'wallet_id'          => $wallet->id,
                'user_id'            => $userId,
                'type'               => $type->value,
                'direction'          => $direction->value,
                'amount'             => $amount,
                'balance_after'      => $newBalance,
                'currency'           => $wallet->currency->value,
                'reference_type'     => $reference ? $reference::class : null,
                'reference_id'       => $reference?->getKey(),
                'note'               => $note,
                'created_by_user_id' => $createdByUserId,
            ]);

            $wallet->update(['balance' => $newBalance]);

            return $txn;
        });
    }
}
