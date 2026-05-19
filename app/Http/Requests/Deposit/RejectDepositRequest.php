<?php

namespace App\Http\Requests\Deposit;

use Illuminate\Foundation\Http\FormRequest;

class RejectDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:5'],
        ];
    }
}
