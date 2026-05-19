<?php

namespace App\Models;

use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'wallet_id',
        'user_id',
        'type',
        'direction',
        'amount',
        'balance_after',
        'currency',
        'reference_type',
        'reference_id',
        'note',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'type'          => WalletTransactionType::class,
            'direction'     => WalletTransactionDirection::class,
            'amount'        => 'integer',
            'balance_after' => 'integer',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
