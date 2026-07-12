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
            'stock_date' => 'date',
            'is_closed' => 'boolean',
            'sales_limit' => 'decimal:2',
        ];
    }
}
