<?php

namespace Tests\Feature\TwoD;

use App\Contracts\TwoDLiveProvider;
use App\Exceptions\TwoDProviderException;
use App\Support\NoopSleeper;
use App\Support\Sleeper;
use App\Support\TwoD\TwoDLiveSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeTwoDLiveProvider;
use Tests\TestCase;

class CaptureTwoDSideNumbersCommandTest extends TestCase
{
    use RefreshDatabase;

    /** A Wednesday that is not a SET closure. */
    private const TRADING_DAY = '2026-07-22';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.twod.driver' => 'htayapi']);
        $this->app->bind(Sleeper::class, NoopSleeper::class);
        // 10:15 Bangkok — past the 09:30 MMT slot's 10:00 publication, so the
        // freshness guard's time gate does not reject rows. It is INSIDE the
        // carry-over grace window, but no test here seeds an earlier day, so the
        // value comparison passes on a null baseline.
        Carbon::setTestNow(Carbon::parse(self::TRADING_DAY.' 10:15', 'Asia/Bangkok'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** The exact upstream shape, straight from the HtayApi docs. */
    private function payload(): array
    {
        return [
            'author' => 'HTAY API',
            'data' => '0',
            'live' => ['set' => '??', 'val' => '??', 'live' => '73'],
            'date' => self::TRADING_DAY.' 22:31:12 +0630',
            'morning' => ['modern' => '39', 'internet' => '07', '2d' => '85', 'key' => '485'],
            'evening' => ['modern' => '69', 'internet' => '06', '2d' => '73', 'key' => '473'],
            'taiwan' => ['2d' => '96'],
        ];
    }

    private function fakeProvider(?array $payload = null): void
    {
        $this->fakeProviderSequence([$payload ?? $this->payload()]);
    }

    /** @param  array<int, array>  $payloads  returned one per fetch, last one repeating */
    private function fakeProviderSequence(array $payloads): void
    {
        $snapshots = array_map(
            fn (array $payload) => new TwoDLiveSnapshot(
                upstreamStatus: 200,
                results: [],
                live: null,
                raw: $payload,
            ),
            $payloads,
        );

        $this->app->instance(TwoDLiveProvider::class, new FakeTwoDLiveProvider($snapshots));
    }

    public function test_stores_the_morning_pair(): void
    {
        $this->fakeProvider();

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('two_d_side_numbers', [
            'result_date' => self::TRADING_DAY,
            'slot' => 'morning',
            'modern' => '39',
            'internet' => '07',
        ]);
    }

    /**
     * The carry-over regression test. At 10:15 Bangkok the `evening` block
     * legitimately still holds YESTERDAY's numbers — the morning run must not
     * touch it. This is what makes cross-slot carry-over structurally impossible
     * rather than merely unlikely.
     */
    public function test_the_morning_run_never_writes_an_evening_row(): void
    {
        $this->fakeProvider();

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning'])->assertExitCode(0);

        $this->assertDatabaseMissing('two_d_side_numbers', ['slot' => 'evening']);
        $this->assertDatabaseCount('two_d_side_numbers', 1);
    }

    public function test_stores_the_evening_pair_after_its_own_publication_time(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TRADING_DAY.' 14:45', 'Asia/Bangkok'));
        $this->fakeProvider();

        $this->artisan('twod:capture-side-numbers', ['slot' => 'evening'])->assertExitCode(0);

        $this->assertDatabaseHas('two_d_side_numbers', [
            'slot' => 'evening',
            'modern' => '69',
            'internet' => '06',
        ]);
    }

    /** The store-only contract, asserted directly. */
    public function test_never_writes_settlement_tables(): void
    {
        $this->fakeProvider();

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning'])->assertExitCode(0);

        $this->assertDatabaseCount('two_d_results', 0);
        $this->assertDatabaseCount('bet_settlement_runs', 0);
    }

    public function test_a_market_holiday_stores_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 10:15', 'Asia/Bangkok'));
        $this->fakeProvider();

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning'])->assertExitCode(0);

        $this->assertDatabaseCount('two_d_side_numbers', 0);
    }

    public function test_a_read_before_publication_stores_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TRADING_DAY.' 09:30', 'Asia/Bangkok'));
        $this->fakeProvider();

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning', '--max-attempts' => 1])
            ->assertExitCode(0);

        $this->assertDatabaseCount('two_d_side_numbers', 0);
    }

    public function test_re_running_is_idempotent(): void
    {
        $this->fakeProvider();

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning'])->assertExitCode(0);
        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning'])->assertExitCode(0);

        $this->assertDatabaseCount('two_d_side_numbers', 1);
    }

    public function test_force_overwrites_an_existing_row(): void
    {
        $changed = $this->payload();
        $changed['morning']['modern'] = '11';

        // Bound once as a two-snapshot sequence rather than rebound between
        // runs: Artisan caches the resolved command, so a second
        // $this->app->instance() would never reach it.
        $this->fakeProviderSequence([$this->payload(), $changed]);

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning'])->assertExitCode(0);

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning', '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('two_d_side_numbers', 1);
        $this->assertDatabaseHas('two_d_side_numbers', ['slot' => 'morning', 'modern' => '11']);
    }

    public function test_an_upstream_failure_is_not_fatal(): void
    {
        $this->app->instance(
            TwoDLiveProvider::class,
            FakeTwoDLiveProvider::throwing(new TwoDProviderException('HtayApi daily call budget exhausted.'))
        );

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning', '--max-attempts' => 1])
            ->assertExitCode(0);

        $this->assertDatabaseCount('two_d_side_numbers', 0);
    }

    public function test_a_placeholder_value_is_not_stored(): void
    {
        $payload = $this->payload();
        $payload['morning']['modern'] = '--';
        $payload['morning']['internet'] = '--';
        $this->fakeProvider($payload);

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning', '--max-attempts' => 1])
            ->assertExitCode(0);

        $this->assertDatabaseCount('two_d_side_numbers', 0);
    }

    public function test_a_non_htayapi_driver_stores_nothing(): void
    {
        config(['services.twod.driver' => 'thaistock2d']);
        $this->fakeProvider();

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning'])->assertExitCode(0);

        $this->assertDatabaseCount('two_d_side_numbers', 0);
    }

    /**
     * The 2026-08-24 incident: upstream served a bare `"0"` for both halves and
     * it was stored verbatim, because the freshness guard only asks whether the
     * pair differs from yesterday's — not whether it is a number at all.
     */
    public function test_a_malformed_value_is_not_stored(): void
    {
        $payload = $this->payload();
        $payload['morning']['modern'] = '0';
        $payload['morning']['internet'] = '0';
        $this->fakeProvider($payload);

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning', '--max-attempts' => 1])
            ->assertExitCode(0);

        $this->assertDatabaseCount('two_d_side_numbers', 0);
    }

    /**
     * Half a pair is still worth storing — the row stays incomplete, so
     * `already_captured` keeps returning false and a later run fills it in.
     */
    public function test_one_malformed_half_does_not_discard_the_other(): void
    {
        $payload = $this->payload();
        $payload['morning']['modern'] = '7';
        $this->fakeProvider($payload);

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning', '--max-attempts' => 1])
            ->assertExitCode(0);

        $this->assertDatabaseHas('two_d_side_numbers', [
            'slot' => 'morning',
            'modern' => null,
            'internet' => '07',
        ]);
    }

    /**
     * The silent-failure fix. Running out of retries means the day's numbers are
     * gone for good — htayapi serves no history — so it must leave a trace
     * somewhere other than an untimestamped scheduler.log line.
     */
    public function test_exhausting_the_retries_logs_an_error(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'morning @ '.self::TRADING_DAY)
                && $context['reason'] === 'no_data');

        $payload = $this->payload();
        $payload['morning']['modern'] = '--';
        $payload['morning']['internet'] = '--';
        $this->fakeProvider($payload);

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning', '--max-attempts' => 2])
            ->assertExitCode(0);
    }

    public function test_an_upstream_error_that_never_clears_is_logged(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => $context['reason'] === 'upstream_error'
                && $context['upstream_message'] === 'HtayApi daily call budget exhausted.');

        $this->app->instance(
            TwoDLiveProvider::class,
            FakeTwoDLiveProvider::throwing(new TwoDProviderException('HtayApi daily call budget exhausted.'))
        );

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning', '--max-attempts' => 2])
            ->assertExitCode(0);
    }

    /** A holiday is a correct outcome, not a lost day — it must stay quiet. */
    public function test_a_terminal_skip_logs_nothing(): void
    {
        Log::shouldReceive('error')->never();

        Carbon::setTestNow(Carbon::parse('2026-07-29 10:15', 'Asia/Bangkok'));
        $this->fakeProvider();

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning'])->assertExitCode(0);
    }

    public function test_a_successful_capture_logs_nothing(): void
    {
        Log::shouldReceive('error')->never();

        $this->fakeProvider();

        $this->artisan('twod:capture-side-numbers', ['slot' => 'morning'])->assertExitCode(0);
    }

    public function test_an_invalid_slot_fails(): void
    {
        $this->artisan('twod:capture-side-numbers', ['slot' => 'afternoon'])
            ->assertExitCode(1);
    }
}
