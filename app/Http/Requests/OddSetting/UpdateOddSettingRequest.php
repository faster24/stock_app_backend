<?php

namespace App\Http\Requests\OddSetting;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Http\Requests\Auth\AuthFormRequest;
use App\Models\OddSetting;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateOddSettingRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'bet_type' => [
                'sometimes',
                'required',
                'string',
                Rule::in(array_column(BetType::cases(), 'value')),
            ],
            'currency' => ['sometimes', 'required', 'string', Rule::in(array_column(Currency::cases(), 'value'))],
            'user_type' => ['sometimes', 'required', 'string', Rule::in(array_column(OddSettingUserType::cases(), 'value'))],
            'odd' => ['sometimes', 'required', 'numeric', 'min:0'],
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

            $oddSetting = $this->route('oddSetting');

            if (! $oddSetting instanceof OddSetting) {
                return;
            }

            $resolvedBetType = (string) $this->input('bet_type', $oddSetting->bet_type->value);
            $resolvedCurrency = (string) $this->input('currency', $oddSetting->currency->value);
            $resolvedUserType = (string) $this->input('user_type', $oddSetting->user_type->value);

            $exists = OddSetting::query()
                ->where('bet_type', $resolvedBetType)
                ->where('currency', $resolvedCurrency)
                ->where('user_type', $resolvedUserType)
                ->whereKeyNot($oddSetting->id)
                ->exists();

            if ($exists) {
                $validator->errors()->add(
                    'bet_type',
                    'The bet_type has already been taken for the selected currency and user type.'
                );
            }
        });
    }
}
