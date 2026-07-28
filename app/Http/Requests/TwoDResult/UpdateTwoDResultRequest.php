<?php

namespace App\Http\Requests\TwoDResult;

use App\Http\Requests\Auth\AuthFormRequest;

class UpdateTwoDResultRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'twod' => ['required', 'digits:2'],
            'confirm_revert' => ['sometimes', 'boolean'],
            'reason' => ['required_if:confirm_revert,true', 'string', 'max:500'],
        ];
    }
}
