<?php

namespace App\Models;

use App\Enums\BetType;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TemporaryOddAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'bet_type',
        'currency',
        'number',
        'target_opentime',
        'stock_date',
        'base_odd',
        'adjusted_odd',
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
            'base_odd' => 'decimal:2',
            'adjusted_odd' => 'decimal:2',
        ];
    }
}
