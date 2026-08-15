<?php

namespace App\Models;

use App\Enums\BankName;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Wallet extends Model
{
    use HasFactory;

    /** How long a user must wait between changes to their bank details. */
    public const BANK_INFO_COOLDOWN_DAYS = 30;

    protected $fillable = [
        'user_id',
        'balance',
        'currency',
        'currency_locked_at',
        'bank_name',
        'account_name',
        'account_number',
        'bank_info_updated_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * When the bank details may next be changed, or null while no cooldown is
     * running. A wallet that has never completed setup is never stamped, so it
     * stays editable — see WalletBankInfoService.
     */
    public function bankInfoNextAllowedAt(): ?Carbon
    {
        return $this->bank_info_updated_at?->copy()->addDays(self::BANK_INFO_COOLDOWN_DAYS);
    }

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'currency' => Currency::class,
            'currency_locked_at' => 'datetime',
            'bank_name' => BankName::class,
            'bank_info_updated_at' => 'datetime',
        ];
    }
}
