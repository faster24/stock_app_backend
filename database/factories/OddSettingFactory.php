<?php

namespace Database\Factories;

use App\Enums\BetType;
use Illuminate\Database\Eloquent\Factories\Factory;

class OddSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bet_type' => BetType::TWO_D,
            'odd' => '80.00',
            'bet_amount' => 1_000,
            'is_active' => true,
        ];
    }

    public function permutation(): static
    {
        return $this->state([
            'bet_type' => BetType::THREE_D,
            'odd' => '10.00',
            'bet_amount' => 1_000,
        ]);
    }
}
