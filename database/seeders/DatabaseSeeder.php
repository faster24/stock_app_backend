<?php

namespace Database\Seeders;

use App\Enums\BetType;
use App\Models\OddSetting;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app('Spatie\\Permission\\PermissionRegistrar')->forgetCachedPermissions();

        call_user_func(['Spatie\\Permission\\Models\\Role', 'findOrCreate'], 'admin');
        call_user_func(['Spatie\\Permission\\Models\\Role', 'findOrCreate'], 'user');

        app('Spatie\\Permission\\PermissionRegistrar')->forgetCachedPermissions();

        User::query()->firstOrCreate([
            'email' => 'test@example.com',
        ], [
            'name' => 'Test User',
            'password' => Hash::make('password'),
        ]);

        OddSetting::query()->updateOrCreate([
            'bet_type' => BetType::STRAIGHT,
        ], [
            'odd' => '80.00',
            'is_active' => true,
        ]);

        OddSetting::query()->updateOrCreate([
            'bet_type' => BetType::PERMUTATION,
        ], [
            'odd' => '10.00',
            'is_active' => true,
        ]);
    }
}
