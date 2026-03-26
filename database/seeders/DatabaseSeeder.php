<?php

namespace Database\Seeders;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Models\OddSetting;
use App\Models\User;
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
        call_user_func(['Spatie\\Permission\\Models\\Role', 'findOrCreate'], 'vip', $guard);

        app('Spatie\\Permission\\PermissionRegistrar')->forgetCachedPermissions();

        $this->call(AdminSeeder::class);

        User::query()->updateOrCreate([
            'email' => 'test@example.com',
        ], [
            'username' => 'testuser',
            'password' => Hash::make('password'),
        ]);

        $defaultOdds = [
            BetType::TWO_D->value => '80.00',
            BetType::THREE_D->value => '80.00',
        ];

        foreach (BetType::cases() as $betType) {
            foreach (Currency::cases() as $currency) {
                foreach (OddSettingUserType::cases() as $userType) {
                    OddSetting::query()->updateOrCreate([
                        'bet_type' => $betType,
                        'currency' => $currency,
                        'user_type' => $userType,
                    ], [
                        'odd' => $defaultOdds[$betType->value],
                        'is_active' => true,
                    ]);
                }
            }
        }
    }
}
