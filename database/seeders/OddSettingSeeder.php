<?php

namespace Database\Seeders;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Models\OddSetting;
use Illuminate\Database\Seeder;

class OddSettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            BetType::TWO_D->value => [
                Currency::MMK->value => '80.00',
                Currency::THB->value => '70.00',
            ],
            BetType::THREE_D->value => [
                Currency::MMK->value => '700.00',
                Currency::THB->value => '550.00',
            ],
        ];

        foreach (BetType::cases() as $betType) {
            foreach (Currency::cases() as $currency) {
                foreach (OddSettingUserType::cases() as $userType) {
                    OddSetting::query()->updateOrCreate([
                        'bet_type' => $betType,
                        'currency' => $currency,
                        'user_type' => $userType,
                    ], [
                        'odd' => $defaults[$betType->value][$currency->value],
                        'is_active' => true,
                    ]);
                }
            }
        }
    }
}
