<?php

namespace Tests\Unit\Services\TwoD;

use App\Enums\TwoDSideSlot;
use App\Models\TwoDSideNumber;
use App\Services\Set\TradingCalendar;
use App\Services\TwoD\TwoDSideNumberFreshnessGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TwoDSideNumberFreshnessGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A Wednesday that is not a SET closure. The 09:30 MMT slot publishes at
     * 10:00 Bangkok; the 14:00 MMT slot at 14:30 Bangkok.
     */
    private const TRADING_DAY = '2026-07-22';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function guard(): TwoDSideNumberFreshnessGuard
    {
        return new TwoDSideNumberFreshnessGuard(new TradingCalendar);
    }

    private function freezeBangkok(string $dateTime): void
    {
        Carbon::setTestNow(Carbon::parse($dateTime, 'Asia/Bangkok'));
    }

    private function seedPair(string $resultDate, TwoDSideSlot $slot, string $modern, string $internet): void
    {
        TwoDSideNumber::query()->create([
            'result_date' => $resultDate,
            'slot' => $slot->value,
            'modern' => $modern,
            'internet' => $internet,
        ]);
    }

    /** The 2026-07-29 incident: a market holiday where upstream kept serving stale numbers. */
    public function test_a_market_holiday_is_never_fresh(): void
    {
        $this->freezeBangkok('2026-07-29 18:00');

        $this->assertFalse($this->guard()->isFresh(TwoDSideSlot::MORNING, '39', '07'));
    }

    public function test_a_weekend_is_never_fresh(): void
    {
        $this->freezeBangkok('2026-07-25 18:00'); // Saturday

        $this->assertFalse($this->guard()->isFresh(TwoDSideSlot::MORNING, '39', '07'));
    }

    public function test_a_value_read_before_publication_is_stale(): void
    {
        // 09:59 Bangkok is one minute before the 09:30 MMT slot publishes.
        $this->freezeBangkok(self::TRADING_DAY.' 09:59');

        $this->assertFalse($this->guard()->isFresh(TwoDSideSlot::MORNING, '39', '07'));
    }

    public function test_a_value_read_at_publication_is_fresh_when_nothing_is_stored(): void
    {
        $this->freezeBangkok(self::TRADING_DAY.' 10:00');

        $this->assertTrue($this->guard()->isFresh(TwoDSideSlot::MORNING, '39', '07'));
    }

    public function test_an_identical_pair_is_carryover_within_the_grace_window(): void
    {
        $this->seedPair('2026-07-20', TwoDSideSlot::MORNING, '39', '07');
        $this->freezeBangkok(self::TRADING_DAY.' 10:05');

        $this->assertFalse($this->guard()->isFresh(TwoDSideSlot::MORNING, '39', '07'));
    }

    public function test_a_pair_differing_in_only_one_half_is_fresh_within_the_grace_window(): void
    {
        $this->seedPair('2026-07-20', TwoDSideSlot::MORNING, '39', '07');
        $this->freezeBangkok(self::TRADING_DAY.' 10:05');

        $this->assertTrue($this->guard()->isFresh(TwoDSideSlot::MORNING, '39', '08'));
    }

    /**
     * Past the grace window the clock is the authority. Without this a day whose
     * pair legitimately repeated would be dropped entirely — the failure that
     * kept the 12:01 settlement slot empty with a stored baseline of '85'.
     */
    public function test_an_identical_pair_is_fresh_once_the_grace_window_passes(): void
    {
        $this->seedPair('2026-07-20', TwoDSideSlot::MORNING, '39', '07');
        $this->freezeBangkok(self::TRADING_DAY.' 10:15');

        $this->assertTrue($this->guard()->isFresh(TwoDSideSlot::MORNING, '39', '07'));
    }

    public function test_the_evening_slot_gate_is_two_thirty_pm_bangkok(): void
    {
        $this->freezeBangkok(self::TRADING_DAY.' 14:29');
        $this->assertFalse($this->guard()->isFresh(TwoDSideSlot::EVENING, '69', '06'));

        $this->freezeBangkok(self::TRADING_DAY.' 14:45');
        $this->assertTrue($this->guard()->isFresh(TwoDSideSlot::EVENING, '69', '06'));
    }

    /** Each slot keeps its own baseline — the morning pair must not gate the evening one. */
    public function test_slots_do_not_share_a_stored_baseline(): void
    {
        $this->seedPair('2026-07-20', TwoDSideSlot::MORNING, '69', '06');
        $this->freezeBangkok(self::TRADING_DAY.' 14:35');

        $this->assertTrue($this->guard()->isFresh(TwoDSideSlot::EVENING, '69', '06'));
    }
}
