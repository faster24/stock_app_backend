<?php

namespace App\Http\Requests\Withdrawal;

use Illuminate\Foundation\Http\FormRequest;

class CompleteWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payout_proof' => ['required', 'file', 'image', 'max:10240'],
            'admin_note'   => ['nullable', 'string', 'max:1000'],
        ];
    }
}
