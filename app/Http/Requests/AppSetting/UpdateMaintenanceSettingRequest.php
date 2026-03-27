<?php

namespace App\Http\Requests\AppSetting;

use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Validator;

class UpdateMaintenanceSettingRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = ['is_enabled', 'message'];

            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, sprintf('The %s field is not allowed.', $field));
            }
        });
    }
}
