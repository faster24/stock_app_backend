<?php

namespace App\Http\Requests\ThreeDResult;

use App\Http\Requests\Auth\AuthFormRequest;

class StoreThreeDResultRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'stock_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'threed' => [
                'required',
                'digits:3',
            ],
        ];
    }
}
