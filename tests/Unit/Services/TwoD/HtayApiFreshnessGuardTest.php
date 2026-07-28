<?php

namespace Tests\Unit\Services\TwoD;

use App\Models\TwoDResult;
use App\Services\TwoD\HtayApiFreshnessGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HtayApiFreshnessGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-29 13:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    public function test_no_prior_row_is_optimistically_fresh(): void
    {
        $guard = new HtayApiFreshnessGuard;

        $this->assertTrue($guard->isFresh('12:01', '85'));
    }

    public function test_prior_row_with_a_different_value_is_fresh(): void
    {
        $this->seedResult('2026-07-28', '12:01:00', '99');

        $guard = new HtayApiFreshnessGuard;

        $this->assertTrue($guard->isFresh('12:01', '85'));
    }

    public function test_prior_row_with_the_same_value_on_an_earlier_day_is_stale(): void
    {
        $this->seedResult('2026-07-28', '12:01:00', '85');

        $guard = new HtayApiFreshnessGuard;

        $this->assertFalse($guard->isFresh('12:01', '85'));
    }

    public function test_only_the_most_recent_prior_row_is_compared(): void
    {
        $this->seedResult('2026-07-26', '12:01:00', '85');
        $this->seedResult('2026-07-27', '12:01:00', '11');
        $this->seedResult('2026-07-28', '12:01:00', '22');

        $guard = new HtayApiFreshnessGuard;

        // Most recent prior (07-28) is '22', different from '85' -> fresh,
        // even though an older day (07-26) shares the same value.
        $this->assertTrue($guard->isFresh('12:01', '85'));
        $this->assertFalse($guard->isFresh('12:01', '22'));
    }

    public function test_a_same_day_row_is_ignored(): void
    {
        $this->seedResult('2026-07-29', '12:01:00', '85');

        $guard = new HtayApiFreshnessGuard;

        $this->assertTrue($guard->isFresh('12:01', '85'));
    }
}
