<?php

namespace Database\Factories;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Round;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class BetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'round_id' => Round::factory(),
            'bet_type' => BetType::STRAIGHT,
            'amount' => 1_000,
            'status' => BetStatus::PENDING,
            'placed_at' => Carbon::parse('2026-01-01 10:01:00'),
        ];
    }

    public function forUserAndRound(User $user, Round $round): static
    {
        return $this->for($user)->for($round);
    }
}
