<?php

namespace App\Http\Requests\Auth;

use App\Enums\Currency;
use App\Models\User;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class RegisterRequest extends AuthFormRequest
{
    /**
     * E.164 mobile numbers for the two supported countries:
     * Myanmar +95 9XXXXXXX(XX) and Thailand +66 [6|8|9]XXXXXXXX.
     */
    public const PHONE_REGEX = '/^\+(?:959\d{7,9}|66[689]\d{8})$/';

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge([
                'phone' => preg_replace('/[\s\-()]/', '', $this->input('phone')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => [
                'bail',
                'required',
                'string',
                'regex:'.self::PHONE_REGEX,
                Rule::unique('users', 'phone'),
                // Accounts registered before E.164 storage hold the local
                // form (e.g. 0912345678); treat those as the same number.
                function (string $attribute, mixed $value, Closure $fail): void {
                    $legacyLocal = '0'.preg_replace('/^\+(?:95|66)/', '', $value);

                    if (User::query()->where('phone', $legacyLocal)->exists()) {
                        $fail('The phone has already been taken.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'currency' => ['nullable', 'string', new Enum(Currency::class)],
            'pin' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/', 'confirmed'],
            'pin_confirmation' => ['required', 'string', 'size:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid Myanmar (+95) or Thailand (+66) mobile number.',
        ];
    }
}
