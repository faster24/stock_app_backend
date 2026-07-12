<?php

namespace App\Http\Requests\BettingDistribution;

use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class ReopenNumberControlsRequest extends AuthFormRequest
{
    public function rules(): array
    {
        $betType = $this->input('bet_type');
        $maxNumber = $betType === '3D' ? 999 : 99;

        return [
            'target_opentime' => [
                'required_if:bet_type,2D',
                'nullable',
                'string',
                Rule::in(['11:00:00', '12:01:00', '15:00:00', '16:30:00', '']),
            ],
            'stock_date' => ['required', 'date_format:Y-m-d'],
            'bet_type' => ['required', Rule::in(['2D', '3D'])],
            'currency' => ['required', Rule::in(['MMK', 'THB'])],
            'numbers' => ['required', 'array', 'min:1'],
            'numbers.*' => ['required', 'integer', 'min:0', "max:{$maxNumber}"],
        ];
    }

    public function messages(): array
    {
        return [
            'target_opentime.in' => 'The selected target opentime is invalid. Valid times: 11:00:00, 12:01:00, 15:00:00, 16:30:00.',
            'bet_type.in' => 'The selected bet type is invalid.',
            'currency.in' => 'The selected currency is invalid.',
            'numbers.required' => 'At least one number is required.',
            'numbers.*.max' => 'The number is out of range for the selected bet type.',
        ];
    }
}
