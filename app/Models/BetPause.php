<?php

namespace App\Models;

use App\Enums\BetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BetPause extends Model
{
    use HasFactory;

    protected $fillable = [
        'bet_type',
        'is_enabled',
        'pause_from',
        'message',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'bet_type' => BetType::class,
            'is_enabled' => 'boolean',
            'pause_from' => 'datetime',
        ];
    }
}
