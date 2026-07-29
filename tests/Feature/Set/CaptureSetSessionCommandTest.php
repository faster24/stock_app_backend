<?php

namespace Tests\Feature\Set;

use App\Contracts\SetScraper;
use App\Exceptions\SetScraperException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeSetScraper;
use Tests\TestCase;

class CaptureSetSessionCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A Wednesday that is not a SET closure — the first Wednesday after
     * 2026-06-01 is the Queen's Birthday, on which the exchange is shut.
     */
    private function weekday(): string
    {
        return '2026-06-10';
    }

    private function saturday(): string
    {
        return Carbon::parse('2026-06-01')->next(Carbon::SATURDAY)->toDateString();
    }

    public function test_capture_stores_calculated_two_d_for_a_close_session(): void
    {
        $this->app->instance(SetScraper::class, FakeSetScraper::returning(
            FakeSetScraper::reading(last: '1644.39', value: '89284959005')
        ));

        $this->artisan('set:capture', ['session' => 'evening_close', '--date' => $this->weekday()])
            ->assertExitCode(0);

        $this->assertDatabaseHas('set_session_results', [
            'result_date' => $this->weekday(),
            'session' => 'evening_close',
            'two_d' => '95',
            'digit_one' => '9',
            'digit_two' => '5',
            'stabilized' => true,
        ]);
    }

    public function test_open_session_uses_the_open_index_field(): void
    {
        // open=1624.85 -> 5 ; value ...005 -> 5  => "55"
        $this->app->instance(SetScraper::class, FakeSetScraper::returning(
            FakeSetScraper::reading(last: '1644.39', open: '1624.85', value: '89284959005', marketStatus: 'Open')
        ));

        $this->artisan('set:capture', ['session' => 'morning_open', '--date' => $this->weekday()])
            ->assertExitCode(0);

        $this->assertDatabaseHas('set_session_results', [
            'session' => 'morning_open',
            'two_d' => '55',
        ]);
    }

    public function test_re_running_is_idempotent_on_date_and_session(): void
    {
        $this->app->instance(SetScraper::class, FakeSetScraper::returning(FakeSetScraper::reading()));

        $args = ['session' => 'evening_close', '--date' => $this->weekday()];
        $this->artisan('set:capture', $args)->assertExitCode(0);
        $this->artisan('set:capture', array_merge($args, ['--force' => true]))->assertExitCode(0);

        $this->assertDatabaseCount('set_session_results', 1);
    }

    public function test_weekend_is_skipped_without_scraping(): void
    {
        $fake = FakeSetScraper::returning(FakeSetScraper::reading());
        $this->app->instance(SetScraper::class, $fake);

        $this->artisan('set:capture', ['session' => 'evening_close', '--date' => $this->saturday()])
            ->assertExitCode(0);

        $this->assertDatabaseCount('set_session_results', 0);
        $this->assertSame([], $fake->captured, 'scraper must not run on a non-trading day');
    }

    public function test_configured_holiday_is_skipped(): void
    {
        config(['set.holidays' => [$this->weekday()]]);
        $fake = FakeSetScraper::returning(FakeSetScraper::reading());
        $this->app->instance(SetScraper::class, $fake);

        $this->artisan('set:capture', ['session' => 'morning_close', '--date' => $this->weekday()])
            ->assertExitCode(0);

        $this->assertDatabaseCount('set_session_results', 0);
        $this->assertSame([], $fake->captured);
    }

    public function test_scraper_failure_returns_failure_and_stores_nothing(): void
    {
        $this->app->instance(SetScraper::class, FakeSetScraper::throwing(
            new SetScraperException('Incapsula blocked the request')
        ));

        $this->artisan('set:capture', ['session' => 'evening_close', '--date' => $this->weekday()])
            ->assertExitCode(1);

        $this->assertDatabaseCount('set_session_results', 0);
    }

    public function test_invalid_session_argument_fails(): void
    {
        $this->artisan('set:capture', ['session' => 'nope'])->assertExitCode(1);
    }
}
