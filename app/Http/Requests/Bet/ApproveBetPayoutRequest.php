<?php

namespace App\Http\Requests\Bet;

use App\Http\Requests\Auth\AuthFormRequest;

class ApproveBetPayoutRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'payout_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payout_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
