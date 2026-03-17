<?php

namespace App\Http\Requests\Wallet;

use App\Enums\BankName;
use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class StoreWalletBankInfoRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', Rule::in(array_column(BankName::cases(), 'value'))],
            'account_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:255'],
        ];
    }
}
