<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ThreeDResult extends Model
{
    use HasFactory;

    protected $table = 'three_d_results';

    protected $fillable = [
        'stock_date',
        'threed',
    ];

    protected function casts(): array
    {
        return [
            'stock_date' => 'date:Y-m-d',
        ];
    }
}
