<?php

namespace App\Http\Requests\TwoDResult;

use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class StoreTwoDResultRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'stock_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'open_time' => ['required', Rule::in(['12:01', '16:30', '12:01:00', '16:30:00'])],
            'twod' => ['required', 'digits:2'],
        ];
    }
}
