<?php

namespace Tests\Unit\Services\Set;

use App\Services\Set\TradingCalendar;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TradingCalendarTest extends TestCase
{
    private TradingCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar = new TradingCalendar;
        config(['set.holidays' => []]);
    }

    public function test_weekend_is_not_a_trading_day(): void
    {
        $saturday = Carbon::parse('2026-06-01')->next(Carbon::SATURDAY);
        $sunday = Carbon::parse('2026-06-01')->next(Carbon::SUNDAY);

        $this->assertFalse($this->calendar->isTradingDay($saturday));
        $this->assertFalse($this->calendar->isTradingDay($sunday));
    }

    public function test_normal_weekday_is_a_trading_day(): void
    {
        $wednesday = Carbon::parse('2026-06-01')->next(Carbon::WEDNESDAY);

        $this->assertTrue($this->calendar->isTradingDay($wednesday));
    }

    public function test_configured_holiday_is_not_a_trading_day(): void
    {
        $wednesday = Carbon::parse('2026-06-01')->next(Carbon::WEDNESDAY);
        config(['set.holidays' => [$wednesday->toDateString()]]);

        $this->assertTrue($this->calendar->isHoliday($wednesday));
        $this->assertFalse($this->calendar->isTradingDay($wednesday));
    }
}
