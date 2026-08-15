<?php

namespace App\Services\Wallet;

use App\Exceptions\BankInfoUpdateTooSoonException;
use App\Models\Wallet;
use App\Services\Service;

class WalletBankInfoService extends Service
{
    private const BANK_INFO_KEYS = ['bank_name', 'account_name', 'account_number'];

    public function showForUser(string $userId): ?Wallet
    {
        return Wallet::query()->where('user_id', $userId)->first();
    }

    public function createForUser(string $userId, array $attributes): Wallet
    {
        $this->guardBankInfoCooldown($userId);

        $wallet = Wallet::query()->updateOrCreate(
            ['user_id' => $userId],
            $this->bankInfoAttributes($attributes),
        );

        return $this->stampCooldownIfSetupComplete($wallet);
    }

    public function updateForUser(string $userId, array $attributes): Wallet
    {
        $this->guardBankInfoCooldown($userId);

        $wallet = Wallet::query()->updateOrCreate(
            ['user_id' => $userId],
            $this->providedBankInfoAttributes($attributes),
        );

        return $this->stampCooldownIfSetupComplete($wallet);
    }

    public function clearForUser(string $userId): void
    {
        Wallet::query()->where('user_id', $userId)->update([
            'bank_name' => null,
            'account_name' => null,
            'account_number' => null,
            'bank_info_updated_at' => null,
        ]);
    }

    /**
     * The cooldown gates *changes*, not the initial setup. Stamping every write
     * meant a user whose setup was interrupted — a failed wallet refresh, a
     * dropped connection between picking a currency and saving bank details —
     * came back to finish it and got locked out of their own account for 30
     * days. Only a wallet that is fully set up has something worth protecting,
     * so an incomplete one stays unstamped and freely retryable.
     */
    private function stampCooldownIfSetupComplete(Wallet $wallet): Wallet
    {
        if (! $this->isSetupComplete($wallet)) {
            return $wallet;
        }

        $wallet->update(['bank_info_updated_at' => now()]);

        return $wallet->refresh();
    }

    private function isSetupComplete(Wallet $wallet): bool
    {
        if ($wallet->currency === null) {
            return false;
        }

        foreach (self::BANK_INFO_KEYS as $key) {
            if ($wallet->{$key} === null) {
                return false;
            }
        }

        return true;
    }

    private function guardBankInfoCooldown(string $userId): void
    {
        $wallet = Wallet::query()->where('user_id', $userId)->first();

        $nextAllowedAt = $wallet?->bankInfoNextAllowedAt();

        if ($nextAllowedAt !== null && now()->lt($nextAllowedAt)) {
            throw new BankInfoUpdateTooSoonException($nextAllowedAt);
        }
    }

    private function bankInfoAttributes(array $attributes): array
    {
        return [
            'bank_name' => $attributes['bank_name'] ?? null,
            'account_name' => $attributes['account_name'] ?? null,
            'account_number' => $attributes['account_number'] ?? null,
        ];
    }

    private function providedBankInfoAttributes(array $attributes): array
    {
        $payload = [];

        foreach (self::BANK_INFO_KEYS as $key) {
            if (array_key_exists($key, $attributes)) {
                $payload[$key] = $attributes[$key];
            }
        }

        return $payload;
    }
}
