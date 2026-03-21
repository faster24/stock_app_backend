<?php

namespace App\Http\Requests\Bet;

use App\Enums\BetStatus;
use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateBetStatusRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    BetStatus::ACCEPTED->value,
                    BetStatus::REJECTED->value,
                    BetStatus::REFUNDED->value,
                ]),
            ],
        ];
    }
}
