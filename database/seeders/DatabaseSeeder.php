<?php

namespace Database\Seeders;

use App\Enums\BetType;
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

        OddSetting::query()->updateOrCreate([
            'bet_type' => BetType::TWO_D,
        ], [
            'odd' => '80.00',
            'bet_amount' => 1_000,
            'is_active' => true,
        ]);

        OddSetting::query()->updateOrCreate([
            'bet_type' => BetType::THREE_D,
        ], [
            'odd' => '10.00',
            'bet_amount' => 1_000,
            'is_active' => true,
        ]);
    }
}
