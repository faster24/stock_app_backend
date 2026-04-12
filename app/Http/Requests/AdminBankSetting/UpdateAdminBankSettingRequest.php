<?php

namespace App\Http\Requests\AdminBankSetting;

use App\Enums\Currency;
use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateAdminBankSettingRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'bank_name' => ['sometimes', 'string', 'max:255'],
            'account_holder_name' => ['sometimes', 'string', 'max:255'],
            'account_number' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
            'currency' => ['sometimes', new Enum(Currency::class)],
        ];
    }
}
