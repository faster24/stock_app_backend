<?php

namespace App\Http\Requests\Bet;

use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class BulkApproveBetPayoutRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'stock_date' => ['required', 'date_format:Y-m-d'],
            'target_opentime' => [
                'required',
                'string',
                Rule::in(['11:00:00', '12:01:00', '15:00:00', '16:30:00']),
            ],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
