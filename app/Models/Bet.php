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

class Bet extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $appends = [
        'winning_number',
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
        return $this->hasMany(BetNumber::class)->orderBy('number');
    }

    public function getWinningNumberAttribute(): ?int
    {
        if ($this->bet_result_status !== BetResultStatus::WON || $this->settled_result_history_id === null) {
            return null;
        }
        if ($this->bet_type === BetType::TWO_D) {
            return TwoDResult::where('history_id', $this->settled_result_history_id)->value('twod');
        }
        $date = str_replace('3d-result-', '', $this->settled_result_history_id);

        return ThreeDResult::whereDate('stock_date', $date)->value('threed');
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
            // 'date:Y-m-d' rather than 'date': a bare 'date' cast serializes as a
            // full UTC datetime, and with APP_TIMEZONE=Asia/Bangkok midnight
            // becomes 17:00Z the PREVIOUS day — clients formatting in UTC read
            // the wrong calendar day.
            'stock_date' => 'date:Y-m-d',
            'total_amount' => 'decimal:2',
            'status' => BetStatus::class,
            'bet_result_status' => BetResultStatus::class,
            'payout_status' => BetPayoutStatus::class,
            'paid_out_at' => 'datetime',
            'paid_out_by_user_id' => 'string',
            'payout_reference' => 'string',
            'payout_note' => 'string',
            'placed_at' => 'datetime:Y-m-d\TH:i:s\Z',
            'settled_at' => 'datetime:Y-m-d\TH:i:s\Z',
            'settled_result_history_id' => 'string',
        ];
    }
}
