<?php

namespace App\Models;

use App\Enums\BankName;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'balance',
        'currency',
        'currency_locked_at',
        'bank_name',
        'account_name',
        'account_number',
        'bank_info_updated_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'balance'            => 'integer',
            'currency'           => Currency::class,
            'currency_locked_at' => 'datetime',
            'bank_name'          => BankName::class,
            'bank_info_updated_at' => 'datetime',
        ];
    }
}
