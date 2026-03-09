<?php

namespace App\Http\Requests\Announcement;

use App\Http\Requests\Auth\AuthFormRequest;

class UpdateAnnouncementRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'summary' => ['sometimes', 'required', 'string'],
            'description' => ['sometimes', 'required', 'string'],
        ];
    }
}
