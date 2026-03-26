<?php

namespace App\Models;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OddSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'bet_type',
        'currency',
        'user_type',
        'odd',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'bet_type' => BetType::class,
            'currency' => Currency::class,
            'user_type' => OddSettingUserType::class,
            'odd' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
