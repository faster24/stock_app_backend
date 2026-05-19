<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Models\AdminBankSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminBankSetting>
 */
class AdminBankSettingFactory extends Factory
{
    protected $model = AdminBankSetting::class;

    public function definition(): array
    {
        return [
            'bank_name'           => 'KBZ',
            'account_holder_name' => 'Zarmani 108',
            'account_number'      => $this->faker->numerify('##########'),
            'is_active'           => true,
            'is_primary'          => false,
            'currency'            => Currency::MMK,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function primary(): static
    {
        return $this->state(['is_primary' => true]);
    }
}
