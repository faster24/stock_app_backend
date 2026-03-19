<?php

namespace Database\Factories;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class BetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bet_type' => BetType::TWO_D,
            'amount' => 1_000,
            'status' => BetStatus::PENDING,
            'placed_at' => Carbon::parse('2026-01-01 10:01:00'),
        ];
    }
}
