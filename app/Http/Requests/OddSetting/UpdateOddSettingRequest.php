<?php

namespace App\Http\Requests\OddSetting;

use App\Enums\BetType;
use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class UpdateOddSettingRequest extends AuthFormRequest
{
    public function rules(): array
    {
        $oddSetting = $this->route('oddSetting');

        return [
            'bet_type' => [
                'sometimes',
                'required',
                'string',
                Rule::in(array_column(BetType::cases(), 'value')),
                Rule::unique('odd_settings', 'bet_type')->ignore($oddSetting?->id),
            ],
            'odd' => ['sometimes', 'required', 'numeric', 'min:0'],
            'bet_amount' => ['sometimes', 'required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
