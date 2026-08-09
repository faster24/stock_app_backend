<?php

namespace Tests\Feature\TwoD;

use App\Contracts\TwoDLiveProvider;
use App\Exceptions\TwoDProviderException;
use App\Models\User;
use App\Support\TwoD\TwoDLiveData;
use App\Support\TwoD\TwoDLiveSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeTwoDLiveProvider;
use Tests\TestCase;

class TwoDLiveApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function tokenHeader(): array
    {
        $user = User::factory()->normalUser()->create();

        return ['Authorization' => 'Bearer '.$user->createToken('auth_token')->plainTextToken];
    }

    private function bindProvider(TwoDLiveProvider $provider): void
    {
        $this->app->instance(TwoDLiveProvider::class, $provider);
    }

    private function snapshot(string $twod = '73'): TwoDLiveSnapshot
    {
        return new TwoDLiveSnapshot(
            upstreamStatus: 200,
            results: [],
            live: new TwoDLiveData(
                twod: $twod,
                time: '2026-07-22 14:00:00',
                date: '2026-07-22',
                dateTime: '2026-07-22 14:00:00',
                set: '1234.56',
                value: '789.01',
                raw: [],
            ),
            raw: [],
        );
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/two-d-results/live')->assertUnauthorized();
    }

    public function test_returns_the_live_ticker_payload(): void
    {
        $this->bindProvider(new FakeTwoDLiveProvider($this->snapshot()));

        $this->withHeaders($this->tokenHeader())
            ->getJson('/api/v1/two-d-results/live')
            ->assertOk()
            ->assertJsonPath('message', 'Live 2D value retrieved successfully.')
            ->assertJsonPath('data.live.twod', '73')
            ->assertJsonPath('data.live.set', '1234.56')
            ->assertJsonPath('data.live.value', '789.01')
            ->assertJsonPath('data.live.time', '2026-07-22 14:00:00')
            ->assertJsonPath('data.live.stale', false)
            ->assertJsonPath('errors', null);
    }

    /**
     * An upstream failure must still return 200 with the last known value —
     * the client renders a ticker, not an error banner, and the shared daily
     * budget must not be spent retrying.
     */
    public function test_upstream_failure_degrades_to_a_stale_value_rather_than_an_error(): void
    {
        $headers = $this->tokenHeader();

        $this->bindProvider(new FakeTwoDLiveProvider($this->snapshot('42')));
        $this->withHeaders($headers)->getJson('/api/v1/two-d-results/live')->assertOk();

        Cache::forget('htayapi:live:fresh');
        $this->bindProvider(FakeTwoDLiveProvider::throwing(new TwoDProviderException('upstream down')));

        $this->withHeaders($headers)
            ->getJson('/api/v1/two-d-results/live')
            ->assertOk()
            ->assertJsonPath('data.live.twod', '42')
            ->assertJsonPath('data.live.stale', true);
    }

    public function test_returns_nulls_when_upstream_fails_with_a_cold_cache(): void
    {
        $this->bindProvider(FakeTwoDLiveProvider::throwing(new TwoDProviderException('upstream down')));

        $this->withHeaders($this->tokenHeader())
            ->getJson('/api/v1/two-d-results/live')
            ->assertOk()
            ->assertJsonPath('data.live.twod', null)
            ->assertJsonPath('data.live.stale', true);
    }
}
