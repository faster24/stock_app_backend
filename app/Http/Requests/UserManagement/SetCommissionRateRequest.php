<?php

namespace App\Http\Requests\UserManagement;

use App\Http\Requests\Auth\AuthFormRequest;

class SetCommissionRateRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ];
    }
}
