<?php

namespace Tests\Unit\Services\TwoD;

use App\Contracts\TwoDLiveProvider;
use App\Exceptions\TwoDProviderException;
use App\Services\TwoD\TwoDLiveTickerService;
use App\Support\TwoD\TwoDLiveData;
use App\Support\TwoD\TwoDLiveSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeTwoDLiveProvider;
use Tests\TestCase;

/**
 * The whole point of this service is that upstream cost is bounded by the TTL
 * rather than by user count, so most of these tests are call-counting tests.
 */
class TwoDLiveTickerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        // 14:00 MMT — inside the session, outside both draw windows, so the
        // "warm" TTL applies unless a test re-freezes the clock.
        Carbon::setTestNow(Carbon::parse('2026-07-22 14:00', 'Asia/Yangon'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function snapshot(string $twod = '73', ?string $time = '2026-07-22 14:00:00'): TwoDLiveSnapshot
    {
        return new TwoDLiveSnapshot(
            upstreamStatus: 200,
            results: [],
            live: new TwoDLiveData(
                twod: $twod,
                time: $time,
                date: '2026-07-22',
                dateTime: $time,
                set: '1234.56',
                value: '789.01',
                raw: [],
            ),
            raw: [],
        );
    }

    private function service(TwoDLiveProvider $provider): TwoDLiveTickerService
    {
        return new TwoDLiveTickerService($provider);
    }

    public function test_returns_the_live_values_from_the_provider(): void
    {
        $result = $this->service(new FakeTwoDLiveProvider($this->snapshot()))->current();

        $this->assertSame('73', $result['twod']);
        $this->assertSame('1234.56', $result['set']);
        $this->assertSame('789.01', $result['value']);
        $this->assertSame('2026-07-22 14:00:00', $result['time']);
        $this->assertFalse($result['stale']);
    }

    /**
     * The core guarantee: N reads inside one TTL cost exactly one upstream call.
     * Without this the shared daily key quota scales with concurrent users.
     */
    public function test_repeated_reads_within_the_ttl_hit_upstream_once(): void
    {
        $provider = new CountingTwoDLiveProvider($this->snapshot());
        $service = $this->service($provider);

        for ($i = 0; $i < 25; $i++) {
            $this->assertSame('73', $service->current()['twod']);
        }

        $this->assertSame(1, $provider->calls);
    }

    public function test_a_read_after_the_ttl_expires_refetches(): void
    {
        $provider = new CountingTwoDLiveProvider([$this->snapshot('73'), $this->snapshot('88')]);
        $service = $this->service($provider);

        $this->assertSame('73', $service->current()['twod']);

        // Warm TTL is 20s at 14:00 MMT — still inside it.
        Carbon::setTestNow(Carbon::now()->addSeconds(19));
        $this->assertSame('73', $service->current()['twod']);
        $this->assertSame(1, $provider->calls);

        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $this->assertSame('88', $service->current()['twod']);
        $this->assertSame(2, $provider->calls);
    }

    /**
     * Settlement shares the same daily budget, so a failing ticker must not
     * retry its way through it — it falls back to the last good value instead.
     */
    public function test_serves_the_last_known_value_when_the_provider_fails(): void
    {
        $provider = new CountingTwoDLiveProvider($this->snapshot('73'));
        $this->service($provider)->current();

        Cache::forget('htayapi:live:fresh');

        $failing = FakeTwoDLiveProvider::throwing(new TwoDProviderException('upstream down'));
        $result = $this->service($failing)->current();

        $this->assertSame('73', $result['twod']);
        $this->assertTrue($result['stale']);
    }

    /**
     * HtayApiProvider throws for an exhausted budget exactly as it does for a
     * network failure, so the same fallback covers it.
     */
    public function test_serves_the_last_known_value_when_the_daily_budget_is_exhausted(): void
    {
        $this->service(new FakeTwoDLiveProvider($this->snapshot('42')))->current();

        Cache::forget('htayapi:live:fresh');

        $exhausted = FakeTwoDLiveProvider::throwing(
            new TwoDProviderException('HtayApi daily call budget exhausted.')
        );

        $this->assertSame('42', $this->service($exhausted)->current()['twod']);
    }

    public function test_returns_nulls_when_upstream_fails_and_nothing_was_ever_cached(): void
    {
        $failing = FakeTwoDLiveProvider::throwing(new TwoDProviderException('upstream down'));

        $result = $this->service($failing)->current();

        $this->assertNull($result['twod']);
        $this->assertNull($result['time']);
        $this->assertTrue($result['stale']);
    }

    /**
     * A snapshot whose provider recognised the payload but carried no live block
     * must not overwrite the good cached value with nulls.
     */
    public function test_a_snapshot_without_a_live_block_falls_back_rather_than_caching_nulls(): void
    {
        $this->service(new FakeTwoDLiveProvider($this->snapshot('55')))->current();

        Cache::forget('htayapi:live:fresh');

        $empty = new FakeTwoDLiveProvider(
            new TwoDLiveSnapshot(upstreamStatus: 200, results: [], live: null, raw: [])
        );

        $result = $this->service($empty)->current();

        $this->assertSame('55', $result['twod']);
        $this->assertTrue($result['stale']);
    }

    /**
     * Losing the refresh lock must serve the previous value, not queue behind
     * the winner and not become a second upstream call.
     */
    public function test_a_request_that_loses_the_refresh_lock_serves_the_last_value(): void
    {
        $provider = new CountingTwoDLiveProvider($this->snapshot('73'));
        $this->service($provider)->current();

        Cache::forget('htayapi:live:fresh');

        // Simulate a concurrent refresh already in flight.
        $held = Cache::lock('htayapi:live:refresh', 30);
        $this->assertTrue($held->get());

        try {
            $result = $this->service($provider)->current();
        } finally {
            $held->release();
        }

        $this->assertSame('73', $result['twod']);
        $this->assertTrue($result['stale']);
        $this->assertSame(1, $provider->calls, 'The lock loser must not call upstream.');
    }

    /**
     * Inside a draw window the number actually moves, so the cache turns over
     * in 5s instead of 20s.
     */
    public function test_ttl_tightens_inside_a_draw_window(): void
    {
        // 11:45 MMT sits inside the 11:30-12:10 morning draw window.
        Carbon::setTestNow(Carbon::parse('2026-07-22 11:45', 'Asia/Yangon'));

        $provider = new CountingTwoDLiveProvider($this->snapshot());
        $service = $this->service($provider);

        $service->current();
        $this->assertSame(1, $provider->calls);

        // Past the warm TTL's 20s this would still be one call; at 5s it is two.
        Carbon::setTestNow(Carbon::now()->addSeconds(6));
        $service->current();

        $this->assertSame(2, $provider->calls);
    }

    public function test_ttl_relaxes_outside_market_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-22 03:00', 'Asia/Yangon'));

        $provider = new CountingTwoDLiveProvider($this->snapshot());
        $service = $this->service($provider);

        $service->current();

        // Well past both the hot and warm TTLs, still served from cache.
        Carbon::setTestNow(Carbon::now()->addSeconds(120));
        $service->current();

        $this->assertSame(1, $provider->calls);
    }
}

/**
 * Counts upstream calls. FakeTwoDLiveProvider cannot do this, and call count is
 * precisely what these tests exist to assert.
 */
class CountingTwoDLiveProvider implements TwoDLiveProvider
{
    public int $calls = 0;

    /** @var TwoDLiveSnapshot[] */
    private array $snapshots;

    public function __construct(TwoDLiveSnapshot|array $snapshots)
    {
        $this->snapshots = $snapshots instanceof TwoDLiveSnapshot ? [$snapshots] : array_values($snapshots);
    }

    public function fetch(): TwoDLiveSnapshot
    {
        $this->calls++;

        return count($this->snapshots) > 1 ? array_shift($this->snapshots) : $this->snapshots[0];
    }
}
