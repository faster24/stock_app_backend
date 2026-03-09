<?php

namespace Database\Factories;

use App\Enums\BankName;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'balance' => 100_000,
            'bank_name' => BankName::KBZ,
            'account_name' => 'Test Wallet User',
            'account_number' => '0000000001',
        ];
    }
}
