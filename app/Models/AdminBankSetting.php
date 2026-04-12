<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminBankSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_name',
        'account_holder_name',
        'account_number',
        'is_active',
        'is_primary',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'bank_name' => 'string',
            'account_holder_name' => 'string',
            'account_number' => 'string',
            'is_active' => 'boolean',
            'is_primary' => 'boolean',
            'currency' => Currency::class,
        ];
    }
}
