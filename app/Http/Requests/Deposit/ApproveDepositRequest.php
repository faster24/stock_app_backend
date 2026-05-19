<?php

namespace App\Http\Requests\Deposit;

use Illuminate\Foundation\Http\FormRequest;

class ApproveDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'approved_amount' => ['nullable', 'integer', 'min:1'],
            'admin_note'      => ['nullable', 'string', 'max:1000'],
        ];
    }
}
