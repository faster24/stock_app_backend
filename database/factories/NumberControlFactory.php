<?php

namespace Database\Factories;

use App\Enums\BetType;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class NumberControlFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bet_type' => BetType::TWO_D,
            'currency' => Currency::MMK,
            'number' => $this->faker->numberBetween(0, 99),
            'target_opentime' => '16:30:00',
            'stock_date' => Carbon::now()->toDateString(),
            'is_closed' => true,
            'sales_limit' => null,
            'created_by' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state([
            'is_closed' => true,
            'sales_limit' => null,
        ]);
    }

    public function limited(string $limit = '10000.00'): static
    {
        return $this->state([
            'is_closed' => false,
            'sales_limit' => $limit,
        ]);
    }
}
