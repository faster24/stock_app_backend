<?php

namespace App\Http\Requests\Announcement;

use App\Http\Requests\Auth\AuthFormRequest;

class StoreAnnouncementRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string'],
            'description' => ['required', 'string'],
        ];
    }
}
