<?php

namespace App\Services\Set;

use Carbon\CarbonInterface;

/**
 * Decides whether the SET exchange trades on a given date.
 *
 * Weekends are handled in code; weekday public holidays come from
 * `config('set.holidays')`. `marketStatus` from the live payload is the runtime
 * backstop for any holiday missing from the config list.
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
