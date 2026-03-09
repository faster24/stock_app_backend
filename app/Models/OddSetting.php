<?php

namespace App\Models;

use App\Enums\BetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OddSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'bet_type',
        'odd',
        'bet_amount',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'bet_type' => BetType::class,
            'odd' => 'decimal:2',
            'bet_amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
