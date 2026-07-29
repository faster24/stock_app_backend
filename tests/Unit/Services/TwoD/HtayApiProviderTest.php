<?php

namespace Tests\Unit\Services\TwoD;

use App\Contracts\TwoDLiveProvider;
use App\Exceptions\TwoDProviderException;
use App\Services\TwoD\HtayApiCallBudget;
use App\Services\TwoD\HtayApiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Exercises the real HtayApi provider against a faked HTTP client — never a
 * real request. Every test either fakes `htayapi.com/*` or asserts nothing
 * was sent at all (the budget-exhausted case). RefreshDatabase because the
 * mapper's freshness guard queries two_d_results.
 */
class HtayApiProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the clock to the payload's trading day, past both slots'
        // publication times. Without this the mapper's freshness guard is
        // judged against the real date, so the suite would pass or fail
        // depending on the day it ran — a SET closure withholds every row.
        Carbon::setTestNow(Carbon::parse('2026-07-22 17:30', 'Asia/Bangkok'));

        // The daily budget's cache key is date-only (not per-limit), so reset
        // it before each test to avoid cross-test accumulation within a run.
        (new HtayApiCallBudget(25))->reset();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function provider(): HtayApiProvider
    {
        config([
            'services.twod.driver' => 'htayapi',
            'services.twod.htayapi' => [
                'url' => 'https://htayapi.com/mm-twod/thai/2dlive',
                'key' => 'test-api-key',
                'timeout' => 20,
                'daily_limit' => 25,
            ],
        ]);

        $resolved = $this->app->make(TwoDLiveProvider::class);
        $this->assertInstanceOf(HtayApiProvider::class, $resolved);

        return $resolved;
    }

    private function fullPayload(): array
    {
        return [
            'author' => 'HTAY API',
            'website' => 'https://htayapi.com',
            'country' => 'Thailand',
            'copyright' => 'Legal action will be taken if any unauthorized use of our API is found.',
            'data' => '0',
            'live' => ['set' => '??', 'val' => '??', 'live' => '73'],
            'date' => '2026-07-22 00:43:55 +0630',
            'morning' => ['modern' => '39', 'internet' => '07', '2d' => '85', 'key' => '485'],
            'evening' => ['modern' => '69', 'internet' => '06', '2d' => '73', 'key' => '473'],
            'taiwan' => ['2d' => '96'],
        ];
    }

    public function test_sends_the_key_as_query_param_and_both_headers(): void
    {
        Http::fake([
            'htayapi.com/*' => Http::response($this->fullPayload(), 200),
        ]);

        $this->provider()->fetch();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'htayapi.com')
                && $request['key'] === 'test-api-key'
                && $request->hasHeader('X-HtayApi-Key', 'test-api-key')
                && $request->hasHeader('X-HtayApi-Platform', 'web');
        });
    }

    public function test_maps_result_rows_via_the_real_mapper(): void
    {
        Http::fake([
            'htayapi.com/*' => Http::response($this->fullPayload(), 200),
        ]);

        $snapshot = $this->provider()->fetch();

        $this->assertSame(200, $snapshot->upstreamStatus);
        $this->assertTrue($snapshot->hasResultFor('12:01'));
        $this->assertTrue($snapshot->hasResultFor('16:30'));
        $this->assertNull($snapshot->live);
    }

    public function test_throws_with_status_on_non_2xx_response(): void
    {
        Http::fake([
            'htayapi.com/*' => Http::response(['message' => 'boom'], 500),
        ]);

        try {
            $this->provider()->fetch();
            $this->fail('Expected TwoDProviderException.');
        } catch (TwoDProviderException $e) {
            $this->assertSame(500, $e->upstreamStatus());
        }
    }

    public function test_throws_with_null_status_on_transport_exception(): void
    {
        Http::fake(function (): never {
            throw new \RuntimeException('connection refused');
        });

        try {
            $this->provider()->fetch();
            $this->fail('Expected TwoDProviderException.');
        } catch (TwoDProviderException $e) {
            $this->assertNull($e->upstreamStatus());
        }
    }

    public function test_throws_when_payload_is_not_a_json_object(): void
    {
        Http::fake([
            'htayapi.com/*' => Http::response('not json', 200),
        ]);

        try {
            $this->provider()->fetch();
            $this->fail('Expected TwoDProviderException.');
        } catch (TwoDProviderException $e) {
            $this->assertSame(200, $e->upstreamStatus());
        }
    }

    public function test_budget_exhausted_fails_before_any_network_call(): void
    {
        config([
            'services.twod.driver' => 'htayapi',
            'services.twod.htayapi' => [
                'url' => 'https://htayapi.com/mm-twod/thai/2dlive',
                'key' => 'test-api-key',
                'timeout' => 20,
                'daily_limit' => 0,
            ],
        ]);

        Http::fake();

        $resolved = $this->app->make(TwoDLiveProvider::class);
        $this->assertInstanceOf(HtayApiProvider::class, $resolved);

        try {
            $resolved->fetch();
            $this->fail('Expected TwoDProviderException.');
        } catch (TwoDProviderException $e) {
            $this->assertStringContainsString('budget', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
