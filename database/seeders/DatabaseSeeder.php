<?php

namespace Database\Seeders;

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

        $this->call(OddSettingSeeder::class);
    }
}
