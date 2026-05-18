<?php

namespace App\Http\Requests\BettingDistribution;

use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class AdjustOddsRequest extends AuthFormRequest
{
    public function rules(): array
    {
        $betType = $this->input('bet_type');
        $maxNumber = $betType === '3D' ? 999 : 99;

        return [
            'target_opentime' => [
                'required',
                'string',
                Rule::in(['11:00:00', '12:01:00', '15:00:00', '16:30:00']),
            ],
            'stock_date' => ['required', 'date_format:Y-m-d'],
            'bet_type' => ['required', Rule::in(['2D', '3D'])],
            'currency' => ['required', Rule::in(['MMK', 'THB'])],
            'adjustments' => ['required', 'array', 'min:1'],
            'adjustments.*.number' => ['required', 'integer', 'min:0', "max:{$maxNumber}"],
            'adjustments.*.temp_odd' => ['required', 'numeric', 'between:0.1,500'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_opentime.in' => 'The selected target opentime is invalid. Valid times: 11:00:00, 12:01:00, 15:00:00, 16:30:00.',
            'bet_type.in' => 'The selected bet type is invalid.',
            'currency.in' => 'The selected currency is invalid.',
            'adjustments.required' => 'At least one adjustment is required.',
            'adjustments.*.number.max' => 'The number is out of range for the selected bet type.',
            'adjustments.*.temp_odd.between' => 'The odd must be between 0.1 and 500.',
        ];
    }
}
