<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BetNumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'bet_id',
        'number',
    ];

    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class);
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
        ];
    }
}
