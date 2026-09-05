<?php

namespace Database\Seeders;

use App\Enums\BankName;
use App\Enums\Currency;
use App\Models\User;
use App\Models\Wallet;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Guard;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app('Spatie\\Permission\\PermissionRegistrar')->forgetCachedPermissions();

        $guard = Guard::getDefaultName(User::class);

        call_user_func(['Spatie\\Permission\\Models\\Role', 'findOrCreate'], 'admin', $guard);
        call_user_func(['Spatie\\Permission\\Models\\Role', 'findOrCreate'], 'user', $guard);

        app('Spatie\\Permission\\PermissionRegistrar')->forgetCachedPermissions();

        $this->call(AdminSeeder::class);

        $testUser = User::query()->updateOrCreate([
            'email' => 'test@example.com',
        ], [
            'username' => 'testuser',
            'password' => Hash::make('password'),
        ]);

        Wallet::query()->updateOrCreate([
            'user_id' => $testUser->id,
        ], [
            'balance'            => 100_000,
            'currency'           => Currency::MMK->value,
            'currency_locked_at' => now(),
            'bank_name'          => BankName::KBZ->value,
            'account_name'       => 'Test User',
            'account_number'     => '1111111111',
        ]);

        $this->call(OddSettingSeeder::class);
        $this->call(AdminBankSettingSeeder::class);
    }
}
