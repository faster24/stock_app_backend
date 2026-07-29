<?php

namespace App\Models;

use App\Enums\BetType;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NumberControl extends Model
{
    use HasFactory;

    protected $fillable = [
        'bet_type',
        'currency',
        'number',
        'target_opentime',
        'stock_date',
        'is_closed',
        'sales_limit',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'bet_type' => BetType::class,
            'currency' => Currency::class,
            'number' => 'integer',
            // 'date:Y-m-d' rather than 'date': a bare 'date' cast serializes as a
            // full UTC datetime, and with APP_TIMEZONE=Asia/Bangkok midnight
            // becomes 17:00Z the PREVIOUS day — clients formatting in UTC read
            // the wrong calendar day.
            'stock_date' => 'date:Y-m-d',
            'is_closed' => 'boolean',
            'sales_limit' => 'decimal:2',
        ];
    }
}
