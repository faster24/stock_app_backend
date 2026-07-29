<?php

namespace Tests\Unit\Services\Set;

use App\Services\Set\TradingCalendar;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Guards the shipped `config('set.holidays')` calendar itself, not the
 * TradingCalendar lookup logic (see TradingCalendarTest for that).
 *
 * A missing closure is not a quiet no-op: the upstream 2D feeds keep publishing
 * numbers on a shut market, so an unlisted holiday is ingested as a real result.
 */
class SetHolidayCalendarTest extends TestCase
{
    private TradingCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar = new TradingCalendar;
    }

    public function test_asarnha_bucha_2026_is_not_a_trading_day(): void
    {
        // The closure that exposed the bug: thaistock2d served the same number
        // (73) against all four slots for the whole day.
        $this->assertFalse($this->calendar->isTradingDay(Carbon::parse('2026-07-29')));
    }

    public function test_khao_phansa_2026_is_still_a_trading_day(): void
    {
        // A national holiday, but the exchange trades — listing it would drop a
        // real draw day.
        $this->assertTrue($this->calendar->isTradingDay(Carbon::parse('2026-07-30')));
    }

    public function test_2026_calendar_has_every_published_set_closure(): void
    {
        $expected = [
            '2026-01-01', '2026-01-02', '2026-03-03', '2026-04-06', '2026-04-13',
            '2026-04-14', '2026-04-15', '2026-05-01', '2026-05-04', '2026-06-01',
            '2026-06-03', '2026-07-28', '2026-07-29', '2026-08-12', '2026-10-13',
            '2026-10-23', '2026-12-07', '2026-12-10', '2026-12-31',
        ];

        $configured = array_values(array_filter(
            (array) config('set.holidays'),
            fn (string $date): bool => str_starts_with($date, '2026-')
        ));

        sort($configured);

        $this->assertSame($expected, $configured);
    }

    public function test_every_configured_holiday_is_a_weekday(): void
    {
        // Weekends are excluded in code, so a weekend entry is dead weight and
        // usually a sign the list was transcribed from the wrong year.
        foreach ((array) config('set.holidays') as $date) {
            $this->assertFalse(
                Carbon::parse($date)->isWeekend(),
                "Configured holiday {$date} falls on a weekend."
            );
        }
    }

    public function test_every_configured_holiday_is_a_plain_ymd_date(): void
    {
        foreach ((array) config('set.holidays') as $date) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date);
            $this->assertSame($date, Carbon::parse($date)->toDateString());
        }
    }
}
