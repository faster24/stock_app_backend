<?php

namespace App\Enums;

/**
 * The four daily SET capture sessions for the Myanmar 2D pipeline.
 *
 * Single source of truth for session identity: capture time, scraper mode, which
 * index field feeds the 2D, and (for closes only) the settlement slot it maps to.
 */
enum SetSession: string
{
    case MORNING_OPEN = 'morning_open';
    case MORNING_CLOSE = 'morning_close';
    case AFTERNOON_OPEN = 'afternoon_open';
    case EVENING_CLOSE = 'evening_close';

    public function label(): string
    {
        return match ($this) {
            self::MORNING_OPEN => 'Morning Open',
            self::MORNING_CLOSE => 'Morning Close',
            self::AFTERNOON_OPEN => 'Afternoon Open',
            self::EVENING_CLOSE => 'Evening Close',
        };
    }

    /** Wall-clock capture time in the market timezone, "HH:MM". */
    public function captureTime(): string
    {
        return match ($this) {
            self::MORNING_OPEN => '09:30',
            self::MORNING_CLOSE => '12:01',
            self::AFTERNOON_OPEN => '14:00',
            self::EVENING_CLOSE => '16:30',
        };
    }

    public function isClose(): bool
    {
        return match ($this) {
            self::MORNING_CLOSE, self::EVENING_CLOSE => true,
            default => false,
        };
    }

    /** 'retry' for closes (final value, tolerate latency); 'poll' for opens (oscillating). */
    public function scraperMode(): string
    {
        return $this->isClose() ? 'retry' : 'poll';
    }

    /** Which SET index field drives digit 1: opens read 'open', closes read 'last'. */
    public function indexField(): string
    {
        return $this->isClose() ? 'last' : 'open';
    }

    /**
     * The settlement slot (bets.target_opentime) this session maps to, or null
     * for open sessions which are informational and never settle bets.
     */
    public function settlementOpenTime(): ?string
    {
        return match ($this) {
            self::MORNING_CLOSE => '12:01:00',
            self::EVENING_CLOSE => '16:30:00',
            default => null,
        };
    }
}
