<?php

namespace Database\Factories;

use App\Enums\BetType;
use Illuminate\Database\Eloquent\Factories\Factory;

class OddSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bet_type' => BetType::STRAIGHT,
            'odd' => '80.00',
            'is_active' => true,
        ];
    }

    public function permutation(): static
    {
        return $this->state([
            'bet_type' => BetType::PERMUTATION,
            'odd' => '10.00',
        ]);
    }
}
