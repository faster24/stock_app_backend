<?php

namespace Tests\Feature\Set;

use App\Contracts\SetScraper;
use App\Exceptions\SetScraperException;
use App\Support\Set\SetScrapeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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

    /**
     * The command catches SetScraperException, so nothing else ever reports it.
     * Without this log line a broken scraper reaches set-capture.log and stops
     * there, and the SET feed goes stale with no signal.
     */
    public function test_a_scraper_failure_reaches_the_log(): void
    {
        Log::spy();

        $this->app->instance(SetScraper::class, FakeSetScraper::throwing(
            new SetScraperException('Incapsula blocked the request')
        ));

        $this->artisan('set:capture', ['session' => 'evening_close', '--date' => $this->weekday()])
            ->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'Incapsula blocked the request'));
    }

    public function test_data_the_scraper_returns_but_cannot_be_parsed_reaches_the_log(): void
    {
        Log::spy();

        // Built directly rather than through FakeSetScraper::reading(): the
        // no_data branch turns on the fields being null, and that builder types
        // them as plain strings.
        $this->app->instance(SetScraper::class, FakeSetScraper::returning(new SetScrapeResult(
            httpStatus: 200,
            marketStatus: 'Closed',
            marketDateTime: '2026-07-25T16:35:00+07:00',
            indexLast: null,
            indexOpen: null,
            value: null,
            computed2d: null,
            stabilized: true,
            attempts: 1,
            raw: ['index' => []],
        )));

        $this->artisan('set:capture', ['session' => 'evening_close', '--date' => $this->weekday()])
            ->assertExitCode(1);

        Log::shouldHaveReceived('error')->once();
    }

    /**
     * The counterpart that matters just as much: a closed market is the normal
     * case five days in fourteen. Logging it would put a false alert in the chat
     * every weekend and the channel would be muted within a month.
     */
    public function test_a_skipped_non_trading_day_is_not_logged_as_an_error(): void
    {
        Log::spy();

        $this->app->instance(SetScraper::class, FakeSetScraper::returning(FakeSetScraper::reading()));

        $this->artisan('set:capture', ['session' => 'evening_close', '--date' => $this->saturday()])
            ->assertExitCode(0);

        Log::shouldNotHaveReceived('error');
    }
}
