<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwoDResult extends Model
{
    use HasFactory;

    protected $table = 'two_d_results';

    protected $fillable = [
        'history_id',
        'stock_date',
        'stock_datetime',
        'open_time',
        'twod',
        'set_index',
        'value',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'stock_date' => 'date',
            'stock_datetime' => 'datetime',
            'payload' => 'array',
        ];
    }
}
