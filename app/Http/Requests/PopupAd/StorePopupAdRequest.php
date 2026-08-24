<?php

namespace App\Http\Requests\PopupAd;

use App\Http\Requests\Auth\AuthFormRequest;
use App\Support\Media\ImageUploadPolicy;

class StorePopupAdRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            // Raster formats only, 10 MB — see ImageUploadPolicy.
            'image' => ImageUploadPolicy::rules(),
        ];
    }
}
