<?php

namespace App\Enums;

/**
 * The two daily HtayApi side-number blocks (`modern` / `internet`).
 *
 * These are display-only indicator numbers — no bet ever settles against them.
 * The settlement numbers live in `two_d_results`; these live in
 * `two_d_side_numbers` and the two tables are deliberately not linked.
 *
 * Case names follow the UPSTREAM PAYLOAD KEY (`morning`/`evening`), not the time
 * they are shown at, because the payload key is the durable identity. Note the
 * deliberate mismatch: `EVENING` publishes at 14:00 MMT and is displayed as
 * "Afternoon / 2:00 PM". If HtayApi ever re-times these blocks, only this enum
 * changes.
 */
enum TwoDSideSlot: string
{
    case MORNING = 'morning';
    case EVENING = 'evening';

    public function label(): string
    {
        return match ($this) {
            self::MORNING => 'Morning',
            self::EVENING => 'Afternoon',
        };
    }

    /**
     * Wall-clock publication time in Myanmar time, "HH:MM".
     *
     * Fed to the freshness guard, which converts it to the Asia/Bangkok instant
     * the scheduler runs in.
     */
    public function publicationTime(): string
    {
        return match ($this) {
            self::MORNING => '09:30',
            self::EVENING => '14:00',
        };
    }

    /** The slot time clients render, in the same "HH:MM:SS" form as `two_d_results.open_time`. */
    public function displayTime(): string
    {
        return $this->publicationTime().':00';
    }
}
