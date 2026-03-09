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
        ];
    }

    public function forBetWithNumber(Bet $bet, int $number): static
    {
        return $this->for($bet)->state([
            'number' => $number,
        ]);
    }
}
