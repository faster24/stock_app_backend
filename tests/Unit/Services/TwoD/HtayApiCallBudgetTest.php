<?php

namespace Tests\Unit\Services\TwoD;

use App\Services\TwoD\HtayApiCallBudget;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pure Cache-facade tests — no HTTP, no DB. The test environment forces
 * CACHE_STORE=array (see .env.testing / phpunit.xml), so state is isolated
 * per test process with zero setup.
 */
class HtayApiCallBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new HtayApiCallBudget(0))->reset();
    }

    public function test_allows_exactly_the_configured_number_of_calls_then_denies(): void
    {
        $budget = new HtayApiCallBudget(3);

        $this->assertTrue($budget->tryConsume());
        $this->assertTrue($budget->tryConsume());
        $this->assertTrue($budget->tryConsume());
        $this->assertFalse($budget->tryConsume());
    }

    public function test_remaining_decrements_after_each_successful_consume(): void
    {
        $budget = new HtayApiCallBudget(3);

        $this->assertSame(3, $budget->remaining());
        $budget->tryConsume();
        $this->assertSame(2, $budget->remaining());
        $budget->tryConsume();
        $this->assertSame(1, $budget->remaining());
    }

    public function test_zero_limit_denies_immediately(): void
    {
        $budget = new HtayApiCallBudget(0);

        $this->assertFalse($budget->tryConsume());
        $this->assertSame(0, $budget->remaining());
    }

    public function test_resets_across_a_simulated_day_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 23:59:59', 'Asia/Bangkok'));
        $budget = new HtayApiCallBudget(1);

        $this->assertTrue($budget->tryConsume());
        $this->assertFalse($budget->tryConsume());

        Carbon::setTestNow(Carbon::parse('2026-07-30 00:00:01', 'Asia/Bangkok'));

        $this->assertTrue($budget->tryConsume());

        Carbon::setTestNow();
    }

    public function test_reset_clears_state_deterministically(): void
    {
        $budget = new HtayApiCallBudget(1);

        $this->assertTrue($budget->tryConsume());
        $this->assertFalse($budget->tryConsume());

        $budget->reset();

        $this->assertTrue($budget->tryConsume());
    }

    public function test_two_instances_with_different_limits_share_the_same_underlying_counter(): void
    {
        // The cache key is date-scoped only, not limit-scoped, by design: the
        // counter tracks real calls made against the real upstream quota; the
        // limit is just the ceiling checked against it. This is intentional,
        // not a latent surprise.
        $strict = new HtayApiCallBudget(1);
        $lenient = new HtayApiCallBudget(5);

        $this->assertTrue($strict->tryConsume());
        $this->assertSame(0, $strict->remaining());
        $this->assertSame(4, $lenient->remaining());

        $this->assertTrue($lenient->tryConsume());
        $this->assertSame(3, $lenient->remaining());
        // Shared counter is now at 2; strict's ceiling of 1 clamps to 0, not negative.
        $this->assertSame(0, $strict->remaining());
    }
}
