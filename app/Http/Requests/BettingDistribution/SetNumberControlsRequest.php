<?php

namespace App\Http\Requests\BettingDistribution;

use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class SetNumberControlsRequest extends AuthFormRequest
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
            'controls' => ['required', 'array', 'min:1'],
            'controls.*.number' => ['required', 'integer', 'min:0', "max:{$maxNumber}"],
            'controls.*.action' => ['required', Rule::in(['close', 'limit'])],
            'controls.*.sales_limit' => [
                'required_if:controls.*.action,limit',
                'nullable',
                'numeric',
                'min:1',
                'max:99999999',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'target_opentime.in' => 'The selected target opentime is invalid. Valid times: 11:00:00, 12:01:00, 15:00:00, 16:30:00.',
            'bet_type.in' => 'The selected bet type is invalid.',
            'currency.in' => 'The selected currency is invalid.',
            'controls.required' => 'At least one control is required.',
            'controls.*.number.max' => 'The number is out of range for the selected bet type.',
            'controls.*.action.in' => 'The action must be either close or limit.',
            'controls.*.sales_limit.required_if' => 'A sales limit is required when the action is limit.',
        ];
    }
}
