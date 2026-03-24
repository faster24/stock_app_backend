<?php

namespace Database\Seeders;

use App\Enums\BankName;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate([
            'email' => 'admin@lotto.com',
        ], [
            'username' => 'admin',
            'password' => Hash::make('password'),
        ]);

        $admin->syncRoles(['admin']);

        Wallet::query()->updateOrCreate([
            'user_id' => $admin->id,
        ], [
            'balance' => 0,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'Admin User',
            'account_number' => '0000000000',
        ]);
    }
}
