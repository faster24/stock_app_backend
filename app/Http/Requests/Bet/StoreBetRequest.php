<?php

namespace App\Http\Requests\Bet;

use App\Enums\BetType;
use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class StoreBetRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'round_id' => ['required', 'integer'],
            'bet_type' => [
                'required',
                'string',
                Rule::in(array_column(BetType::cases(), 'value')),
            ],
            'amount' => ['required', 'integer', 'min:1'],
            'bet_numbers' => ['required', 'array'],
            'bet_numbers.*' => ['integer', 'min:0', 'max:255', 'distinct'],
        ];
    }
}
