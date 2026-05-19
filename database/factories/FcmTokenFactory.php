<?php

namespace Database\Factories;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FcmTokenFactory extends Factory
{
    protected $model = FcmToken::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => $this->faker->regexify('[A-Za-z0-9_\-]{163}'),
            'device_type' => $this->faker->randomElement(['android', 'ios', 'web']),
            'device_name' => $this->faker->userAgent(),
            'is_active' => true,
            'last_used_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
