<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Guard;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => $attributes['email_verified_at'] ?? null,
        ]);
    }

    public function admin(): static
    {
        return $this->afterCreating(function (User $user): void {
            app('Spatie\\Permission\\PermissionRegistrar')->forgetCachedPermissions();
            call_user_func(['Spatie\\Permission\\Models\\Role', 'findOrCreate'], 'admin', Guard::getDefaultName($user));

            $user->syncRoles(['admin']);
        });
    }

    public function normalUser(): static
    {
        return $this->afterCreating(function (User $user): void {
            app('Spatie\\Permission\\PermissionRegistrar')->forgetCachedPermissions();
            call_user_func(['Spatie\\Permission\\Models\\Role', 'findOrCreate'], 'user', Guard::getDefaultName($user));

            $user->syncRoles(['user']);
        });
    }

    public function vip(): static
    {
        return $this->afterCreating(function (User $user): void {
            app('Spatie\\Permission\\PermissionRegistrar')->forgetCachedPermissions();
            call_user_func(['Spatie\\Permission\\Models\\Role', 'findOrCreate'], 'vip', Guard::getDefaultName($user));

            $user->syncRoles(['vip']);
        });
    }
}
