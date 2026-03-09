<?php

namespace App\Models;

use App\Enums\BetStatus;
use App\Enums\BetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'round_id',
        'bet_type',
        'amount',
        'status',
        'placed_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function betNumbers(): HasMany
    {
        return $this->hasMany(BetNumber::class);
    }

    public function betResults(): HasMany
    {
        return $this->hasMany(BetResult::class);
    }

    protected function casts(): array
    {
        return [
            'bet_type' => BetType::class,
            'amount' => 'integer',
            'status' => BetStatus::class,
            'placed_at' => 'datetime',
        ];
    }
}
