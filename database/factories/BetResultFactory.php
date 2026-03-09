<?php

namespace Database\Factories;

use App\Enums\BetResultStatus;
use App\Models\Bet;
use App\Models\Result;
use Illuminate\Database\Eloquent\Factories\Factory;

class BetResultFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bet_id' => Bet::factory(),
            'result_id' => Result::factory(),
            'status' => BetResultStatus::WON,
            'payout_amount' => 2_000,
        ];
    }

    public function forBetAndResult(Bet $bet, Result $result): static
    {
        return $this->for($bet)->for($result);
    }
}
