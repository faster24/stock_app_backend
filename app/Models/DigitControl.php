<?php

namespace App\Models;

use App\Enums\BetType;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A first digit the admin has closed for one period. Any 2D number starting with
 * it is refused, except a double (Zatu) or a properly paired reverse (R) bet.
 */
class DigitControl extends Model
{
    use HasFactory;

    protected $fillable = [
        'bet_type',
        'currency',
        'digit',
        'target_opentime',
        'stock_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'bet_type' => BetType::class,
            'currency' => Currency::class,
            'digit' => 'integer',
            // 'date:Y-m-d' rather than 'date', for the same reason as
            // NumberControl: a bare 'date' cast serializes as a full UTC
            // datetime, and with APP_TIMEZONE=Asia/Bangkok midnight becomes
            // 17:00Z the PREVIOUS day.
            'stock_date' => 'date:Y-m-d',
        ];
    }
}
