<?php

namespace App\Http\Requests\UserManagement;

use App\Http\Requests\Auth\AuthFormRequest;

class ResetUserSecurityPinRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ];
    }
}
