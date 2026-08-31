<?php

namespace Tests\Feature\Alerting;

use App\Logging\CreateTelegramAlertLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Tests\TestCase;

class TelegramAlertChannelTest extends TestCase
{
    private function logger(array $overrides = []): Logger
    {
        return (new CreateTelegramAlertLogger)(array_merge([
            'bot_token' => 'test-token',
            'chat_id' => '-100123',
            'environment' => 'testing',
            'throttle_seconds' => 300,
            'timeout' => 5,
            'level' => 'error',
        ], $overrides));
    }

    public function test_an_error_is_delivered_to_the_chat(): void
    {
        Http::fake();

        $this->logger()->error('Queue backlog: 90 job(s)', ['depth' => 90]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
                && $request['chat_id'] === '-100123'
                && str_contains($request['text'], 'Queue backlog: 90 job(s)')
                && str_contains($request['text'], 'testing')
                && str_contains($request['text'], '"depth": 90');
        });
    }

    public function test_records_below_the_level_are_ignored(): void
    {
        Http::fake();

        $logger = $this->logger();
        $logger->warning('Upstream slow');
        $logger->info('Routine');

        Http::assertNothingSent();
    }

    /**
     * The reason the throttle exists: one broken endpoint under load emits the
     * same record hundreds of times, and a flooded chat gets muted.
     */
    public function test_repeats_of_the_same_message_are_collapsed_into_one_alert(): void
    {
        Http::fake();

        $logger = $this->logger();
        for ($i = 0; $i < 20; $i++) {
            $logger->error('Payment gateway unreachable');
        }

        Http::assertSentCount(1);
    }

    public function test_a_different_message_still_gets_through(): void
    {
        Http::fake();

        $logger = $this->logger();
        $logger->error('Payment gateway unreachable');
        $logger->error('Settlement provider timed out');

        Http::assertSentCount(2);
    }

    public function test_throttling_can_be_disabled(): void
    {
        Http::fake();

        $logger = $this->logger(['throttle_seconds' => 0]);
        $logger->error('Same message');
        $logger->error('Same message');

        Http::assertSentCount(2);
    }

    /**
     * An alert that cannot be delivered must not take the request down with it.
     */
    public function test_a_delivery_failure_never_escapes_the_handler(): void
    {
        Http::fake(fn () => throw new \RuntimeException('Telegram unreachable'));

        $this->logger()->error('Something broke');

        // Reaching here without an exception is the assertion.
        $this->assertTrue(true);
    }

    public function test_the_channel_is_inert_without_credentials(): void
    {
        Http::fake();

        $logger = $this->logger(['bot_token' => null, 'chat_id' => null]);

        $this->assertInstanceOf(NullHandler::class, $logger->getHandlers()[0]);

        $logger->error('Something broke');

        Http::assertNothingSent();
    }

    public function test_a_cache_outage_does_not_swallow_the_alert(): void
    {
        Http::fake();

        // The throttle asks the cache first. A cache outage is itself worth
        // hearing about, so the throttle fails open rather than dropping the
        // alert it was only ever meant to deduplicate.
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('Cache down'));

        $this->logger()->error('Something broke');

        Http::assertSentCount(1);
    }
}
