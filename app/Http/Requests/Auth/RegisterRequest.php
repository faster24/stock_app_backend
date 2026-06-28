<?php

namespace App\Http\Requests\Auth;

use App\Enums\Currency;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class RegisterRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'currency' => ['nullable', 'string', new Enum(Currency::class)],
            'pin' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/', 'confirmed'],
            'pin_confirmation' => ['required', 'string', 'size:6'],
        ];
    }
}
