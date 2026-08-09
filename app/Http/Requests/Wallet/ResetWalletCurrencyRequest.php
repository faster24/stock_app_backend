<?php

namespace App\Http\Requests\Wallet;

use App\Http\Requests\Auth\AuthFormRequest;

class ResetWalletCurrencyRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
