<?php

namespace Database\Factories;

use App\Models\PopupAd;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PopupAd>
 */
class PopupAdFactory extends Factory
{
    protected $model = PopupAd::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'link_url' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
