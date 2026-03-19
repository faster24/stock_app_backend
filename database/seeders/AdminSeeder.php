<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->firstOrCreate([
            'email' => 'admin@lotto.com',
        ], [
            'name' => 'Admin User',
            'password' => Hash::make('password'),
        ]);

        $admin->syncRoles(['admin']);
    }
}
