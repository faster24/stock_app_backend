<?php

namespace App\Models;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Bet extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'bet_slip',
        'bet_type',
        'target_opentime',
        'stock_date',
        'amount',
        'total_amount',
        'status',
        'bet_result_status',
        'payout_status',
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
            'target_opentime' => 'string',
            'stock_date' => 'date',
            'amount' => 'integer',
            'total_amount' => 'decimal:2',
            'status' => BetStatus::class,
            'bet_result_status' => BetResultStatus::class,
            'payout_status' => BetPayoutStatus::class,
            'placed_at' => 'datetime',
            'settled_at' => 'datetime',
            'settled_result_history_id' => 'string',
        ];
    }
}
