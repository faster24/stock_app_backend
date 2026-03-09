<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Result extends Model
{
    use HasFactory;

    protected $fillable = [
        'round_id',
        'winning_number',
        'settled_at',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function betResults(): HasMany
    {
        return $this->hasMany(BetResult::class);
    }

    protected function casts(): array
    {
        return [
            'winning_number' => 'integer',
            'settled_at' => 'datetime',
        ];
    }
}
