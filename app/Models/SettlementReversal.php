<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SettlementReversal extends Model
{
    public const STATUS_REVERTING = 'REVERTING';

    public const STATUS_COMPLETED = 'COMPLETED';

    protected $fillable = [
        'history_id',
        'bet_type',
        'two_d_result_id',
        'three_d_result_id',
        'reverted_winning_number',
        'reverted_by_user_id',
        'reason',
        'status',
        'run_settled_at',
        'original_summary',
        'summary',
        'total_debited',
        'total_shortfall',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'original_summary' => 'array',
            'summary' => 'array',
            'run_settled_at' => 'datetime',
            'completed_at' => 'datetime',
            'reverted_winning_number' => 'integer',
            'total_debited' => 'integer',
            'total_shortfall' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SettlementReversalItem::class);
    }
}
