<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\WithdrawalStatus;
use App\Support\Media\ImageUploadPolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Withdrawal extends Model implements HasMedia
{
    use HasFactory, HasUuids, InteractsWithMedia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'currency',
        'amount',
        'status',
        'bank_snapshot',
        'admin_note',
        'rejection_reason',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'      => WithdrawalStatus::class,
            'currency'    => Currency::class,
            'amount'      => 'integer',
            'bank_snapshot' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('payout_proof')
            ->singleFile()
            // Last line of defence for callers that skip the FormRequest.
            ->acceptsMimeTypes(ImageUploadPolicy::MIME_TYPES);
    }

    public function getPayoutProofAttribute(): array
    {
        $media = $this->getFirstMedia('payout_proof');

        if ($media === null) {
            return [
                'exists'       => false,
                'download_url' => null,
                'file_name'    => null,
                'mime_type'    => null,
                'size'         => null,
            ];
        }

        return [
            'exists'       => true,
            'download_url' => route('withdrawals.proof', ['withdrawal' => $this->getKey()]),
            'file_name'    => $media->file_name,
            'mime_type'    => $media->mime_type,
            'size'         => $media->size,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
