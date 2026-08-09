<?php

namespace App\Services\Wallet;

use App\Enums\Currency;
use App\Models\Bet;
use App\Models\Wallet;
use App\Services\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalletCurrencyService extends Service
{
    public function setForUser(string $userId, Currency $currency): Wallet
    {
        return DB::transaction(function () use ($userId, $currency) {
            $wallet = Wallet::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($wallet !== null && $wallet->currency !== null) {
                throw ValidationException::withMessages([
                    'currency' => ['Wallet currency is already set and cannot be changed.'],
                ]);
            }

            if ($wallet === null) {
                return Wallet::create([
                    'user_id'            => $userId,
                    'balance'            => 0,
                    'currency'           => $currency->value,
                    'currency_locked_at' => now(),
                ]);
            }

            $wallet->update([
                'currency'           => $currency->value,
                'currency_locked_at' => now(),
            ]);

            return $wallet->refresh();
        });
    }

    /**
     * Admin-only escape hatch. The currency is write-once for users, so an
     * account set up with the wrong one is otherwise stuck for good. Clearing
     * the bank cooldown alongside it lets the user redo setup immediately
     * instead of waiting out the 30 days.
     *
     * Every bet, deposit and withdrawal carries its own currency, so the reset
     * is refused once the wallet has money or betting history behind it —
     * changing it there would leave those records describing another currency.
     */
    public function resetForUser(string $userId, bool $force = false): Wallet
    {
        return DB::transaction(function () use ($userId, $force) {
            $wallet = Wallet::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                throw ValidationException::withMessages([
                    'user' => ['This user has no wallet to reset.'],
                ]);
            }

            if (! $force) {
                if ($wallet->balance > 0) {
                    throw ValidationException::withMessages([
                        'currency' => ['Cannot reset currency on a wallet with a balance.'],
                    ]);
                }

                if (Bet::query()->where('user_id', $userId)->exists()) {
                    throw ValidationException::withMessages([
                        'currency' => ['Cannot reset currency for a user who has placed bets.'],
                    ]);
                }
            }

            $wallet->update([
                'currency'             => null,
                'currency_locked_at'   => null,
                'bank_name'            => null,
                'account_name'         => null,
                'account_number'       => null,
                'bank_info_updated_at' => null,
            ]);

            return $wallet->refresh();
        });
    }
}
