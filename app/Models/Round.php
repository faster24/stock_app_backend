<?php

namespace App\Models;

use App\Enums\RoundStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Round extends Model
{
    use HasFactory;

    protected $fillable = [
        'round_number',
        'status',
        'opens_at',
        'closes_at',
        'settled_at',
    ];

    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    public function result(): HasOne
    {
        return $this->hasOne(Result::class);
    }

    protected function casts(): array
    {
        return [
            'status' => RoundStatus::class,
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
