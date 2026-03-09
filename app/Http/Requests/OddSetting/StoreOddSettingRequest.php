<?php

namespace App\Http\Requests\OddSetting;

use App\Enums\BetType;
use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class StoreOddSettingRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'bet_type' => [
                'required',
                'string',
                Rule::in(array_column(BetType::cases(), 'value')),
                Rule::unique('odd_settings', 'bet_type'),
            ],
            'odd' => ['required', 'numeric', 'min:0'],
            'bet_amount' => ['required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
