<?php

namespace Database\Factories;

use App\Enums\RoundStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class RoundFactory extends Factory
{
    private static int $nextRoundNumber = 1000;

    public function definition(): array
    {
        $opensAt = Carbon::parse('2026-01-01 10:00:00')->addMinutes(self::$nextRoundNumber - 1000);

        return [
            'round_number' => self::$nextRoundNumber++,
            'status' => RoundStatus::OPEN,
            'opens_at' => $opensAt,
            'closes_at' => (clone $opensAt)->addMinutes(5),
            'settled_at' => null,
        ];
    }

    public function settled(): static
    {
        return $this->state(function (array $attributes): array {
            $closesAt = $attributes['closes_at'] instanceof Carbon
                ? $attributes['closes_at']
                : Carbon::parse((string) $attributes['closes_at']);

            return [
                'status' => RoundStatus::SETTLED,
                'settled_at' => (clone $closesAt)->addMinute(),
            ];
        });
    }
}
