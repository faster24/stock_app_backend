<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementReversalItem extends Model
{
    protected $fillable = [
        'settlement_reversal_id',
        'bet_id',
        'user_id',
        'previous_result_status',
        'paid_amount',
        'debited_amount',
        'shortfall_amount',
        'wallet_transaction_id',
        'shortfall_resolved_at',
        'shortfall_resolved_by_user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'paid_amount' => 'integer',
            'debited_amount' => 'integer',
            'shortfall_amount' => 'integer',
            'shortfall_resolved_at' => 'datetime',
        ];
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(SettlementReversal::class, 'settlement_reversal_id');
    }
}
