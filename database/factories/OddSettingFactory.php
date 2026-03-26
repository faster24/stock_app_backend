<?php

namespace Database\Factories;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use Illuminate\Database\Eloquent\Factories\Factory;

class OddSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bet_type' => BetType::TWO_D,
            'currency' => Currency::MMK,
            'user_type' => OddSettingUserType::USER,
            'odd' => '80.00',
            'is_active' => true,
        ];
    }

    public function permutation(): static
    {
        return $this->state([
            'bet_type' => BetType::THREE_D,
            'currency' => Currency::THB,
            'user_type' => OddSettingUserType::VIP,
            'odd' => '10.00',
        ]);
    }
}
