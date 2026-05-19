<?php

namespace App\Http\Requests\Wallet;

use App\Enums\WalletTransactionDirection;
use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rules\Enum;

class AdminBalanceAdjustmentRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'direction' => ['required', 'string', new Enum(WalletTransactionDirection::class)],
            'amount'    => ['required', 'integer', 'min:1'],
            'reason'    => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
