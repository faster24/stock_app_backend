<?php

namespace App\Http\Requests\BetPause;

use App\Enums\BetType;
use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBetPauseRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'bet_type' => ['required', 'string', Rule::in(array_column(BetType::cases(), 'value'))],
            'is_enabled' => ['required', 'boolean'],
            'pause_from' => ['required_if:is_enabled,true', 'nullable', 'date'],
            'message' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'pause_from.required_if' => 'The pause from time is required when enabling a pause.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = ['bet_type', 'is_enabled', 'pause_from', 'message'];

            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, sprintf('The %s field is not allowed.', $field));
            }
        });
    }
}
