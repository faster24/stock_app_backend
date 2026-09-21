<?php

namespace App\Http\Requests\BettingDistribution;

use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class SetHotFirstDigitsRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            // 2D only: a 3D number has no first-digit row on any board here, so
            // there is no '' sentinel opentime to accept.
            'target_opentime' => [
                'required',
                'string',
                Rule::in(['11:00:00', '12:01:00', '15:00:00', '16:30:00']),
            ],
            'stock_date' => ['required', 'date_format:Y-m-d'],
            'bet_type' => ['required', Rule::in(['2D'])],
            'currency' => ['required', Rule::in(['MMK', 'THB'])],
            // 'present' rather than 'required': an empty array is the legal
            // "clear every hot digit for this period".
            'digits' => ['present', 'array', 'max:10'],
            'digits.*' => ['integer', 'min:0', 'max:9', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_opentime.in' => 'The selected target opentime is invalid. Valid times: 11:00:00, 12:01:00, 15:00:00, 16:30:00.',
            'bet_type.in' => 'Hot first digits are only supported for 2D.',
            'currency.in' => 'The selected currency is invalid.',
            'digits.present' => 'The digits field is required; send an empty array to clear every hot digit.',
            'digits.*.max' => 'A first digit must be between 0 and 9.',
            'digits.*.distinct' => 'The digits field contains a duplicate value.',
        ];
    }
}
