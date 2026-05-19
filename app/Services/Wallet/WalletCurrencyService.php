<?php

namespace App\Services\Wallet;

use App\Enums\Currency;
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
}
