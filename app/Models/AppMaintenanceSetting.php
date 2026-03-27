<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppMaintenanceSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'is_enabled',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'message' => 'string',
        ];
    }
}
