<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class PopupAd extends Model implements HasMedia
{
    use HasFactory, HasUuids, InteractsWithMedia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'title',
        'link_url',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('ad_image')->singleFile();
    }

    /**
     * Same descriptor shape as Deposit::getProofImageAttribute — the media disk has
     * no public URL, so callers get a route to download through instead.
     */
    public function getImageAttribute(): array
    {
        $media = $this->getFirstMedia('ad_image');

        if ($media === null) {
            return [
                'exists' => false,
                'download_url' => null,
                'file_name' => null,
                'mime_type' => null,
                'size' => null,
            ];
        }

        return [
            'exists' => true,
            'download_url' => route('popup-ads.image', ['popupAd' => $this->getKey()]),
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
        ];
    }
}
