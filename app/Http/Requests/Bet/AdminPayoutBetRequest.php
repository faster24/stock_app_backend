<?php

namespace App\Http\Requests\Bet;

use App\Http\Requests\Auth\AuthFormRequest;

class AdminPayoutBetRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'payout_proof_image' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
            ],
            'payout_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payout_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

