<?php

namespace App\Http\Requests\Auth;

class ChangeSecurityPinRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            // The account password, not the current PIN — see
            // AuthService::changeSecurityPin() for why.
            'password' => ['required', 'string'],
            'pin' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/', 'confirmed'],
            'pin_confirmation' => ['required', 'string', 'size:6'],
        ];
    }
}
