<?php

namespace App\Models;

use App\Enums\TwoDSideSlot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A day's `modern`/`internet` pair for one HtayApi side slot.
 *
 * Display-only. Nothing here participates in settlement — see
 * {@see \App\Services\TwoD\TwoDSideNumberCaptureService}.
 */
class TwoDSideNumber extends Model
{
    use HasFactory;

    protected $table = 'two_d_side_numbers';

    protected $fillable = [
        'result_date',
        'slot',
        'modern',
        'internet',
        'captured_at',
        'raw_payload',
    ];

    /**
     * The API has no Resource layer — controllers hand models straight to
     * response()->json(), so every attribute ships. `raw_payload` holds the
     * whole upstream slot block, INCLUDING its `2d` settlement number; exposing
     * it would invite a client to render a settlement value inside a row
     * labelled 09:30 or 14:00. Keep it hidden.
     */
    protected $hidden = ['raw_payload'];

    protected $appends = ['display_time'];

    protected function casts(): array
    {
        return [
            // 'date:Y-m-d' rather than 'date': a bare 'date' cast serializes as a
            // full UTC datetime, and with APP_TIMEZONE=Asia/Bangkok midnight
            // becomes 17:00Z the PREVIOUS day — clients formatting in UTC read
            // the wrong calendar day.
            'result_date' => 'date:Y-m-d',
            'slot' => TwoDSideSlot::class,
            'captured_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    /** Slot time in "HH:MM:SS" form, so clients need no slot->time table of their own. */
    public function getDisplayTimeAttribute(): ?string
    {
        return $this->slot?->displayTime();
    }
}
