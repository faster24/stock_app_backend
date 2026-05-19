<?php

namespace App\Http\Requests\Deposit;

use App\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'admin_bank_setting_id' => ['required', 'integer', 'exists:admin_bank_settings,id'],
            'currency'              => ['required', 'string', new Enum(Currency::class)],
            'claimed_amount'        => ['required', 'integer', 'min:1'],
            'transfer_note'         => ['nullable', 'string', 'max:255'],
            'proof_image'           => ['required', 'file', 'image', 'max:10240'],
        ];
    }
}
