<?php

namespace Database\Factories;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class BetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'transaction_id_last_two_digits' => str_pad((string) fake()->numberBetween(0, 99), 2, '0', STR_PAD_LEFT),
            'bet_type' => BetType::TWO_D,
            'currency' => Currency::MMK,
            'total_amount' => '1000.00',
            'status' => BetStatus::PENDING,
            'placed_at' => Carbon::parse('2026-01-01 10:01:00'),
        ];
    }
}
