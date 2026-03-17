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
            'round_id' => ['prohibited'],
            'bet_type' => [
                'sometimes',
                'required',
                'string',
                Rule::in(array_column(BetType::cases(), 'value')),
            ],
            'amount' => ['sometimes', 'required', 'integer', 'min:1'],
            'bet_numbers' => ['sometimes', 'required', 'array'],
            'bet_numbers.*' => ['integer', 'min:0', 'max:255', 'distinct'],
        ];
    }
}
