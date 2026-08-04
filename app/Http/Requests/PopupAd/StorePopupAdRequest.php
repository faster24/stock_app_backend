<?php

namespace App\Http\Requests\PopupAd;

use App\Http\Requests\Auth\AuthFormRequest;

class StorePopupAdRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            // 10 MB matches config/media-library.php max_file_size.
            'image' => ['required', 'file', 'image', 'max:10240'],
        ];
    }
}
