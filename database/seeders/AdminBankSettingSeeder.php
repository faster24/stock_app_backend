<?php

namespace Database\Seeders;

use App\Models\AdminBankSetting;
use Illuminate\Database\Seeder;

class AdminBankSettingSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'bank_name'           => 'KBZ',
                'account_holder_name' => 'Zarmani 108',
                'account_number'      => '0000000000',
                'currency'            => 'MMK',
                'is_active'           => true,
                'is_primary'          => true,
            ],
            [
                'bank_name'           => 'KBANK',
                'account_holder_name' => 'Zarmani 108',
                'account_number'      => '1111111111',
                'currency'            => 'THB',
                'is_active'           => true,
                'is_primary'          => true,
            ],
        ];

        foreach ($accounts as $data) {
            AdminBankSetting::query()->updateOrCreate(
                ['bank_name' => $data['bank_name'], 'currency' => $data['currency']],
                $data,
            );
        }
    }
}
