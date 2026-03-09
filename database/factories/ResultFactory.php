<?php

namespace Database\Factories;

use App\Models\Round;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class ResultFactory extends Factory
{
    public function definition(): array
    {
        return [
            'round_id' => Round::factory(),
            'winning_number' => 12,
            'settled_at' => Carbon::parse('2026-01-01 10:07:00'),
        ];
    }

    public function forRoundWithWinningNumber(Round $round, int $winningNumber): static
    {
        return $this->for($round)->state([
            'winning_number' => $winningNumber,
        ]);
    }
}
