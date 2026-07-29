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
            // 'date:Y-m-d' rather than 'date': a bare 'date' cast serializes as a
            // full UTC datetime, and with APP_TIMEZONE=Asia/Bangkok midnight
            // becomes 17:00Z the PREVIOUS day — clients formatting in UTC read
            // the wrong calendar day.
            'stock_date' => 'date:Y-m-d',
            'stock_datetime' => 'datetime',
            'payload' => 'array',
        ];
    }
}
