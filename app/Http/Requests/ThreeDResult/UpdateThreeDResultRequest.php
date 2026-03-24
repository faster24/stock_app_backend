<?php

namespace App\Http\Requests\ThreeDResult;

use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class UpdateThreeDResultRequest extends AuthFormRequest
{
    public function rules(): array
    {
        $threeDResult = $this->route('threeDResult');

        return [
            'stock_date' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                Rule::unique('three_d_results', 'stock_date')->ignore($threeDResult?->id),
            ],
            'threed' => [
                'sometimes',
                'required',
                'digits:3',
            ],
        ];
    }
}
