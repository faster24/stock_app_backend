<?php

namespace Tests\Feature\Scheduler;

use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Models\BetNumber;
use App\Models\User;
use App\Support\NoopSleeper;
use App\Support\Sleeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchAndSettleTwoDCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(Sleeper::class, NoopSleeper::class);
    }

    // -------------------------------------------------------------------------
    // Payload helpers
    // -------------------------------------------------------------------------

    private function payloadWithResult(string $openTime = '16:30', string $twod = '05'): array
    {
        return [
            'server_time' => '2026-05-05 17:00:00',
            'live' => [
                'set'   => '1,490.10',
                'value' => '73,115.21',
                'time'  => '2026-05-05 16:31:58',
                'twod'  => $twod,
                'date'  => '2026-05-05',
            ],
            'result' => [
                [
                    'set'            => '1,490.19',
                    'value'          => '38,194.99',
                    'open_time'      => $openTime.':00',
                    'twod'           => $twod,
                    'stock_date'     => '2026-05-05',
                    'stock_datetime' => '2026-05-05 '.$openTime.':01',
                    'history_id'     => '9999999',
                ],
            ],
            'holiday' => ['status' => '2', 'date' => '2026-05-05', 'name' => 'NULL'],
        ];
    }

    /** Result row present but twod == '--' (not finalised); live has a real number. */
    private function payloadWithPendingResult(string $openTime = '16:30', string $liveTwod = '05'): array
    {
        return [
            'server_time' => '2026-05-05 16:35:00',
            'live' => [
                'set'   => '1,490.10',
                'value' => '73,115.21',
                'time'  => '2026-05-05 16:31:58',
                'twod'  => $liveTwod,
                'date'  => '2026-05-05',
            ],
            'result' => [
                [
                    'set'            => '--',
                    'value'          => '--',
                    'open_time'      => $openTime.':00',
                    'twod'           => '--',
                    'stock_date'     => '2026-05-05',
                    'stock_datetime' => '2026-05-05 16:35:00',
                    'history_id'     => null,
                ],
            ],
            'holiday' => ['status' => '2', 'date' => '2026-05-05', 'name' => 'NULL'],
        ];
    }

    /** Live time is for the morning slot, not the evening slot being settled. */
    private function payloadWithWrongSlotLive(string $openTime = '16:30'): array
    {
        $payload = $this->payloadWithPendingResult($openTime);
        $payload['live']['time'] = '2026-05-05 12:01:45';
        $payload['live']['twod'] = '77';

        return $payload;
    }

    /** Live twod is '--' — must not be used as fallback. */
    private function payloadWithEmptyLive(string $openTime = '16:30'): array
    {
        $payload = $this->payloadWithPendingResult($openTime);
        $payload['live']['twod'] = '--';

        return $payload;
    }

    private function createPendingBetForSlot(string $openTime = '16:30:00', int $number = 5): Bet
    {
        $user = User::factory()->normalUser()->create();

        $bet = Bet::factory()->for($user)->create([
            'bet_type'          => BetType::TWO_D,
            'status'            => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::OPEN,
            'target_opentime'   => $openTime,
            'stock_date'        => '2026-05-05',
        ]);

        BetNumber::factory()->forBetWithNumber($bet, $number)->create();

        return $bet;
    }

    // -------------------------------------------------------------------------
    // Happy path — result on first attempt
    // -------------------------------------------------------------------------

    public function test_result_arrives_on_first_attempt_persists_and_settles(): void
    {
        Http::fake([
            'api.thaistock2d.com/live' => Http::response($this->payloadWithResult('16:30', '05'), 200),
        ]);

        $bet = $this->createPendingBetForSlot('16:30:00', 5);

        $this->artisan('twod:fetch-and-settle', [
            'open_time'         => '16:30',
            '--timeout-minutes' => 1,
            '--retry-interval'  => 10,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('two_d_results', [
            'history_id' => '9999999',
            'open_time'  => '16:30:00',
            'twod'       => '05',
        ]);

        $bet->refresh();
        $this->assertSame(BetResultStatus::WON, $bet->bet_result_status);
    }

    // -------------------------------------------------------------------------
    // Happy path — result arrives after retries
    // -------------------------------------------------------------------------

    public function test_result_arrives_after_retries_then_settles(): void
    {
        Http::fake([
            'api.thaistock2d.com/live' => Http::sequence()
                ->push($this->payloadWithPendingResult('16:30'), 200)
                ->push($this->payloadWithPendingResult('16:30'), 200)
                ->push($this->payloadWithResult('16:30', '05'), 200),
        ]);

        $bet = $this->createPendingBetForSlot('16:30:00', 5);

        $this->artisan('twod:fetch-and-settle', [
            'open_time'         => '16:30',
            '--timeout-minutes' => 5,
            '--retry-interval'  => 10,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('two_d_results', ['history_id' => '9999999', 'twod' => '05']);

        $bet->refresh();
        $this->assertSame(BetResultStatus::WON, $bet->bet_result_status);
    }

    // -------------------------------------------------------------------------
    // Live fallback — matches the production bug scenario
    // -------------------------------------------------------------------------

    public function test_live_fallback_triggers_and_settles_when_result_never_arrives(): void
    {
        Http::fake([
            'api.thaistock2d.com/live' => Http::response($this->payloadWithPendingResult('16:30', '05'), 200),
        ]);

        $bet = $this->createPendingBetForSlot('16:30:00', 5);

        $this->artisan('twod:fetch-and-settle', [
            'open_time'         => '16:30',
            '--timeout-minutes' => 1,
            '--retry-interval'  => 10,
            '--max-attempts'    => 3,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('two_d_results', [
            'open_time' => '16:30:00',
            'twod'      => '05',
        ]);

        $bet->refresh();
        $this->assertSame(BetResultStatus::WON, $bet->bet_result_status);
    }

    // -------------------------------------------------------------------------
    // --no-live-fallback — must fail, leave bets unsettled
    // -------------------------------------------------------------------------

    public function test_no_live_fallback_flag_causes_failure_and_skips_settlement(): void
    {
        Http::fake([
            'api.thaistock2d.com/live' => Http::response($this->payloadWithPendingResult('12:01'), 200),
        ]);

        $bet = $this->createPendingBetForSlot('12:01:00', 5);

        $this->artisan('twod:fetch-and-settle', [
            'open_time'          => '12:01',
            '--timeout-minutes'  => 1,
            '--retry-interval'   => 10,
            '--max-attempts'     => 3,
            '--no-live-fallback' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('two_d_results', ['open_time' => '12:01:00']);

        $bet->refresh();
        $this->assertSame(BetResultStatus::OPEN, $bet->bet_result_status);
    }

    // -------------------------------------------------------------------------
    // Live twod is '--' — fallback must not trigger
    // -------------------------------------------------------------------------

    public function test_live_fallback_does_not_trigger_when_live_twod_is_dashes(): void
    {
        Http::fake([
            'api.thaistock2d.com/live' => Http::response($this->payloadWithEmptyLive('16:30'), 200),
        ]);

        $this->artisan('twod:fetch-and-settle', [
            'open_time'         => '16:30',
            '--timeout-minutes' => 1,
            '--retry-interval'  => 10,
            '--max-attempts'    => 3,
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('two_d_results', ['open_time' => '16:30:00']);
    }

    // -------------------------------------------------------------------------
    // Live time is for a different slot — fallback must not trigger
    // -------------------------------------------------------------------------

    public function test_live_fallback_does_not_trigger_when_live_time_is_wrong_slot(): void
    {
        Http::fake([
            'api.thaistock2d.com/live' => Http::response($this->payloadWithWrongSlotLive('16:30'), 200),
        ]);

        $this->artisan('twod:fetch-and-settle', [
            'open_time'         => '16:30',
            '--timeout-minutes' => 1,
            '--retry-interval'  => 10,
            '--max-attempts'    => 3,
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('two_d_results', ['open_time' => '16:30:00']);
    }

    // -------------------------------------------------------------------------
    // API unreachable
    // -------------------------------------------------------------------------

    public function test_command_fails_gracefully_when_api_is_unreachable(): void
    {
        Http::fake([
            'api.thaistock2d.com/live' => Http::response(null, 503),
        ]);

        $this->artisan('twod:fetch-and-settle', [
            'open_time'         => '16:30',
            '--timeout-minutes' => 1,
            '--retry-interval'  => 10,
            '--max-attempts'    => 3,
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('two_d_results', ['open_time' => '16:30:00']);
    }
}
