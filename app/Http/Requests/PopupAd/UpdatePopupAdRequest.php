<?php

namespace App\Http\Requests\PopupAd;

use App\Http\Requests\Auth\AuthFormRequest;

class UpdatePopupAdRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            // Omitted on a plain toggle; when present it replaces the current artwork.
            'image' => ['sometimes', 'file', 'image', 'max:10240'],
        ];
    }
}
