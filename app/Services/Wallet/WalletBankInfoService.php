<?php

namespace App\Services\Wallet;

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
        return Wallet::query()->updateOrCreate(
            ['user_id' => $userId],
            $this->bankInfoAttributes($attributes),
        );
    }

    public function updateForUser(string $userId, array $attributes): Wallet
    {
        return Wallet::query()->updateOrCreate(
            ['user_id' => $userId],
            $this->providedBankInfoAttributes($attributes),
        );
    }

    public function clearForUser(string $userId): void
    {
        Wallet::query()->where('user_id', $userId)->update([
            'bank_name' => null,
            'account_name' => null,
            'account_number' => null,
        ]);
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
