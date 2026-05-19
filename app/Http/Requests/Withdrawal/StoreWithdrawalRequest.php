<?php

namespace App\Http\Requests\Withdrawal;

use App\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', new Enum(Currency::class)],
            'amount'   => ['required', 'integer', 'min:1'],
        ];
    }
}
