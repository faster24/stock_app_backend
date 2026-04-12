<?php

namespace App\Http\Requests\AdminBankSetting;

use App\Enums\Currency;
use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreAdminBankSettingRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:255'],
            'account_holder_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
            'currency' => ['required', new Enum(Currency::class)],
        ];
    }
}
