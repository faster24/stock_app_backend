<?php

namespace App\Http\Requests\OddSetting;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOddSettingRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'bet_type' => [
                'required',
                'string',
                Rule::in(array_column(BetType::cases(), 'value')),
                Rule::unique('odd_settings', 'bet_type')
                    ->where(fn ($query) => $query
                        ->where('currency', (string) $this->input('currency'))
                        ->where('user_type', (string) $this->input('user_type'))),
            ],
            'currency' => ['required', 'string', Rule::in(array_column(Currency::cases(), 'value'))],
            'user_type' => ['required', 'string', Rule::in(array_column(OddSettingUserType::cases(), 'value'))],
            'odd' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = ['bet_type', 'currency', 'user_type', 'odd', 'is_active'];

            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, sprintf('The %s field is not allowed.', $field));
            }
        });
    }
}
