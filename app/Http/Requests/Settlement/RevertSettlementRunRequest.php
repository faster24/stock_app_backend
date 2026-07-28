<?php

namespace App\Http\Requests\Settlement;

use App\Http\Requests\Auth\AuthFormRequest;

class RevertSettlementRunRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
