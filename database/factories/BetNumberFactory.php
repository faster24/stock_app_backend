<?php

namespace Database\Factories;

use App\Models\Bet;
use Illuminate\Database\Eloquent\Factories\Factory;

class BetNumberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bet_id' => Bet::factory(),
            'number' => 12,
            'amount' => 1_000,
            'potential_winning' => '80000.00',
        ];
    }

    public function forBetWithNumber(Bet $bet, int $number, ?int $amount = null): static
    {
        return $this->for($bet)->state([
            'number' => $number,
            'amount' => $amount ?? 1_000,
            'potential_winning' => number_format(($amount ?? 1_000) * 80, 2, '.', ''),
        ]);
    }
}
