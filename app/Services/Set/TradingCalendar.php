<?php

namespace App\Services\Set;

use Carbon\CarbonInterface;

/**
 * Decides whether the SET exchange trades on a given date.
 *
 * Weekends are handled in code; weekday public holidays come from
 * `config('set.holidays')`.
 *
 * This outlived the SET scraper it was written for. The live htayapi path
 * depends on it: HtayApiFreshnessGuard uses it on the settlement path, and both
 * side-number classes use it to skip no-draw days. Neither feed reports "--" on
 * a closure — they serve the previous day's numbers — so a date missing from the
 * config list becomes a real-looking result.
 *
 * The `marketStatus` runtime backstop went with the scraper, so the config list
 * is now the only guard, and it only covers 2026.
 */
class TradingCalendar
{
    public function isTradingDay(CarbonInterface $date): bool
    {
        return ! $this->isWeekend($date) && ! $this->isHoliday($date);
    }

    public function isWeekend(CarbonInterface $date): bool
    {
        return $date->isWeekend();
    }

    public function isHoliday(CarbonInterface $date): bool
    {
        return in_array($date->toDateString(), $this->holidays(), true);
    }

    /**
     * @return string[]
     */
    private function holidays(): array
    {
        return (array) config('set.holidays', []);
    }
}
