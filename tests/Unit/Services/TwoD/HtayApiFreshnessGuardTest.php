<?php

namespace Tests\Unit\Services\TwoD;

use App\Models\TwoDResult;
use App\Services\Set\TradingCalendar;
use App\Services\TwoD\HtayApiFreshnessGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HtayApiFreshnessGuardTest extends TestCase
{
    use RefreshDatabase;

    // 2026-07-22 is a Wednesday and not a SET closure. The 12:01 MMT slot
    // publishes at 12:31 Bangkok;
    // the 16:30 MMT slot at 17:00 Bangkok.
    private const TRADING_DAY = '2026-07-22';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function guard(): HtayApiFreshnessGuard
    {
        return new HtayApiFreshnessGuard(new TradingCalendar);
    }

    private function freezeBangkok(string $dateTime): void
    {
        Carbon::setTestNow(Carbon::parse($dateTime, 'Asia/Bangkok'));
    }

    private function seedResult(string $stockDate, string $openTime, string $twod): void
    {
        TwoDResult::query()->create([
            'history_id' => "seed-{$stockDate}-{$openTime}",
            'stock_date' => $stockDate,
            'open_time' => $openTime,
            'twod' => $twod,
            'payload' => [],
        ]);
    }

    public function test_a_value_read_before_publication_is_stale(): void
    {
        $this->freezeBangkok(self::TRADING_DAY.' 12:00');

        // Nothing stored at all, so the old value comparison would have passed
        // this optimistically — the clock alone rejects it.
        $this->assertFalse($this->guard()->isFresh('12:01', '85'));
    }

    public function test_a_value_read_at_publication_is_fresh_when_nothing_is_stored(): void
    {
        $this->freezeBangkok(self::TRADING_DAY.' 12:31');

        $this->assertTrue($this->guard()->isFresh('12:01', '85'));
    }

    public function test_a_value_matching_the_stored_one_is_carryover_within_the_grace_window(): void
    {
        $this->seedResult('2026-07-20', '12:01:00', '85');
        $this->freezeBangkok(self::TRADING_DAY.' 12:35');

        $this->assertFalse($this->guard()->isFresh('12:01', '85'));
    }

    public function test_a_value_differing_from_the_stored_one_is_fresh_within_the_grace_window(): void
    {
        $this->seedResult('2026-07-20', '12:01:00', '85');
        $this->freezeBangkok(self::TRADING_DAY.' 12:35');

        $this->assertTrue($this->guard()->isFresh('12:01', '07'));
    }

    /**
     * Regression: the live 12:01 outage. The stored baseline was '85' and the
     * day's real number was also '85', which the value comparison rejected on
     * every attempt — and since no row was ever written the baseline never
     * moved, blacklisting '85' indefinitely. Past the grace window the number
     * is now trusted on time alone.
     */
    public function test_a_value_matching_the_stored_one_is_fresh_once_the_grace_window_passes(): void
    {
        $this->seedResult('2026-07-20', '12:01:00', '85');
        $this->freezeBangkok(self::TRADING_DAY.' 12:45');

        $this->assertTrue($this->guard()->isFresh('12:01', '85'));
    }

    public function test_the_evening_slot_gate_is_five_pm_bangkok(): void
    {
        $this->freezeBangkok(self::TRADING_DAY.' 16:45');
        $this->assertFalse($this->guard()->isFresh('16:30', '73'));

        $this->freezeBangkok(self::TRADING_DAY.' 17:05');
        $this->assertTrue($this->guard()->isFresh('16:30', '73'));
    }

    public function test_no_value_is_fresh_on_a_weekend(): void
    {
        // Saturday, well past both publication times.
        $this->freezeBangkok('2026-08-01 18:00');

        $this->assertFalse($this->guard()->isFresh('12:01', '85'));
        $this->assertFalse($this->guard()->isFresh('16:30', '73'));
    }

    public function test_no_value_is_fresh_on_a_configured_holiday(): void
    {
        config(['set.holidays' => [self::TRADING_DAY]]);

        $this->freezeBangkok(self::TRADING_DAY.' 18:00');

        $this->assertFalse($this->guard()->isFresh('12:01', '85'));
    }
}
