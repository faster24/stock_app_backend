<?php

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rule;

class RegisterRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
