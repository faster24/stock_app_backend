<?php

namespace App\Http\Requests\Withdrawal;

use App\Support\Media\ImageUploadPolicy;
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
            'payout_proof' => ImageUploadPolicy::rules(),
            'admin_note'   => ['nullable', 'string', 'max:1000'],
        ];
    }
}
