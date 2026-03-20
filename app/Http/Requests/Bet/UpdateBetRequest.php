<?php

namespace App\Http\Requests\Bet;

use App\Enums\BetType;
use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class UpdateBetRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'pay_slip_image' => ['prohibited'],
            'bet_type' => [
                'sometimes',
                'required',
                'string',
                Rule::in(array_column(BetType::cases(), 'value')),
            ],
            'target_opentime' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['11:00:00', '12:01:00', '15:00:00', '16:30:00']),
            ],
            'amount' => ['sometimes', 'required', 'integer', 'min:1'],
            'bet_numbers' => ['sometimes', 'required', 'array'],
            'status' => ['prohibited'],
            'bet_result_status' => ['prohibited'],
            'payout_status' => ['prohibited'],
            'bet_numbers.*' => ['integer', 'min:0', 'max:255', 'distinct'],
        ];
    }
}
