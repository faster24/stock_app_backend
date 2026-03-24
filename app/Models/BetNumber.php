<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BetNumber extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'bet_id',
        'number',
        'amount',
    ];

    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class);
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'amount' => 'integer',
        ];
    }
}
