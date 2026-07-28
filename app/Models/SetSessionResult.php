<?php

namespace App\Models;

use App\Enums\SetSession;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SetSessionResult extends Model
{
    use HasFactory;

    protected $table = 'set_session_results';

    protected $fillable = [
        'result_date',
        'session',
        'two_d',
        'digit_one',
        'digit_two',
        'set_index_value',
        'set_total_value',
        'market_status',
        'market_datetime',
        'stabilized',
        'attempts',
        'captured_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'result_date' => 'date',
            'session' => SetSession::class,
            'market_datetime' => 'datetime',
            'stabilized' => 'boolean',
            'attempts' => 'integer',
            'captured_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }
}
