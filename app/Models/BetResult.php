<?php

namespace App\Models;

use App\Enums\BetResultStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BetResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'bet_id',
        'result_id',
        'status',
        'payout_amount',
    ];

    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class);
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    protected function casts(): array
    {
        return [
            'status' => BetResultStatus::class,
            'payout_amount' => 'integer',
        ];
    }
}
