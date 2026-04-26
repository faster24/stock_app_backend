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

        return Wallet::query()->updateOrCreate(
            ['user_id' => $userId],
            array_merge(
                $this->bankInfoAttributes($attributes),
                ['bank_info_updated_at' => now()],
            ),
        );
    }

    public function updateForUser(string $userId, array $attributes): Wallet
    {
        $this->guardBankInfoCooldown($userId);

        return Wallet::query()->updateOrCreate(
            ['user_id' => $userId],
            array_merge(
                $this->providedBankInfoAttributes($attributes),
                ['bank_info_updated_at' => now()],
            ),
        );
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

    private function guardBankInfoCooldown(string $userId): void
    {
        $wallet = Wallet::query()->where('user_id', $userId)->first();

        if ($wallet?->bank_info_updated_at !== null) {
            $nextAllowedAt = $wallet->bank_info_updated_at->addDays(30);
            if (now()->lt($nextAllowedAt)) {
                throw new BankInfoUpdateTooSoonException($nextAllowedAt);
            }
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
