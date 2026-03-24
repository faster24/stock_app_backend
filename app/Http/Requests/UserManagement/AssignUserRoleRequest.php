<?php

namespace App\Http\Requests\UserManagement;

use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class AssignUserRoleRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(['user', 'vip'])],
        ];
    }
}
