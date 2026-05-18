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
            'stock_date' => 'date',
            'base_odd' => 'decimal:2',
            'adjusted_odd' => 'decimal:2',
        ];
    }
}
