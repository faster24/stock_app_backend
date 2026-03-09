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
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'bet_type' => BetType::class,
            'odd' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
