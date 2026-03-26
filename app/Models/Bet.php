<?php

namespace App\Models;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Bet extends Model implements HasMedia
{
    use HasFactory;
    use HasUuids;
    use InteractsWithMedia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $appends = [
        'pay_slip',
        'payout_proof',
    ];

    protected $fillable = [
        'user_id',
        'bet_slip',
        'bet_type',
        'currency',
        'target_opentime',
        'stock_date',
        'total_amount',
        'status',
        'bet_result_status',
        'payout_status',
        'paid_out_at',
        'paid_out_by_user_id',
        'payout_reference',
        'payout_note',
        'placed_at',
        'settled_at',
        'settled_result_history_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function betNumbers(): HasMany
    {
        return $this->hasMany(BetNumber::class);
    }

    public function registerMediaCollections(): void
    {
        $this
            ->addMediaCollection('pay_slip')
            ->singleFile();

        $this
            ->addMediaCollection('payout_proof')
            ->singleFile();
    }

    public function getPaySlipAttribute(): array
    {
        $media = $this->getFirstMedia('pay_slip');

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
            'download_url' => route('bets.pay-slip', ['bet' => $this->getKey()]),
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
        ];
    }

    public function getPayoutProofAttribute(): array
    {
        $media = $this->getFirstMedia('payout_proof');

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
            'download_url' => route('bets.payout-proof', ['bet' => $this->getKey()]),
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $bet): void {
            if (blank($bet->bet_slip)) {
                $bet->bet_slip = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'bet_type' => BetType::class,
            'currency' => Currency::class,
            'target_opentime' => 'string',
            'stock_date' => 'date',
            'total_amount' => 'decimal:2',
            'status' => BetStatus::class,
            'bet_result_status' => BetResultStatus::class,
            'payout_status' => BetPayoutStatus::class,
            'paid_out_at' => 'datetime',
            'paid_out_by_user_id' => 'integer',
            'payout_reference' => 'string',
            'payout_note' => 'string',
            'placed_at' => 'datetime',
            'settled_at' => 'datetime',
            'settled_result_history_id' => 'string',
        ];
    }
}
