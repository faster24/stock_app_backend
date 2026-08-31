<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Support\Media\ImageUploadPolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Deposit extends Model implements HasMedia
{
    use HasFactory, HasUuids, InteractsWithMedia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'admin_bank_setting_id',
        'currency',
        'claimed_amount',
        'approved_amount',
        'transfer_note',
        'status',
        'admin_note',
        'rejection_reason',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'          => DepositStatus::class,
            'currency'        => Currency::class,
            'claimed_amount'  => 'integer',
            'approved_amount' => 'integer',
            'reviewed_at'     => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('proof_of_payment')
            ->singleFile()
            // Last line of defence for callers that skip the FormRequest.
            ->acceptsMimeTypes(ImageUploadPolicy::MIME_TYPES);
    }

    public function getProofImageAttribute(): array
    {
        $media = $this->getFirstMedia('proof_of_payment');

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
            'download_url' => route('deposits.proof', ['deposit' => $this->getKey()]),
            'file_name'    => $media->file_name,
            'mime_type'    => $media->mime_type,
            'size'         => $media->size,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function adminBankSetting(): BelongsTo
    {
        return $this->belongsTo(AdminBankSetting::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
