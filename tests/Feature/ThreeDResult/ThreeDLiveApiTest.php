<?php

namespace Tests\Feature\ThreeDResult;

use App\Contracts\ThreeDLiveProvider;
use App\Exceptions\ThreeDProviderException;
use App\Models\User;
use App\Services\ThreeD\HtayApiThreeDLiveProvider;
use App\Services\TwoD\HtayApiCallBudget;
use App\Support\ThreeD\ThreeDDraw;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeThreeDLiveProvider;
use Tests\TestCase;

class ThreeDLiveApiTest extends TestCase
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

    private function bindProvider(ThreeDLiveProvider $provider): void
    {
        $this->app->instance(ThreeDLiveProvider::class, $provider);
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/three-d-results/live')->assertUnauthorized();
    }

    public function test_returns_the_current_draw(): void
    {
        $this->bindProvider(new FakeThreeDLiveProvider(new ThreeDDraw('479', '2026-08-01')));

        $this->withHeaders($this->tokenHeader())
            ->getJson('/api/v1/three-d-results/live')
            ->assertOk()
            ->assertJsonPath('message', 'Live 3D value retrieved successfully.')
            ->assertJsonPath('data.three_d_live.threed', '479')
            ->assertJsonPath('data.three_d_live.stock_date', '2026-08-01')
            ->assertJsonPath('data.stale', false)
            ->assertJsonPath('errors', null);
    }

    /**
     * The vendor quota is shared with the 2D ticker, the history feed and
     * settlement, so a second reader inside the TTL must not become a second
     * upstream call.
     */
    public function test_a_second_request_is_served_from_cache_without_calling_upstream(): void
    {
        $provider = new FakeThreeDLiveProvider(new ThreeDDraw('479', '2026-08-01'));
        $this->bindProvider($provider);
        $headers = $this->tokenHeader();

        $this->withHeaders($headers)->getJson('/api/v1/three-d-results/live')->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/three-d-results/live')->assertOk();

        $this->assertSame(1, $provider->calls);
    }

    public function test_upstream_failure_degrades_to_a_stale_value_rather_than_an_error(): void
    {
        $headers = $this->tokenHeader();

        $this->bindProvider(new FakeThreeDLiveProvider(new ThreeDDraw('479', '2026-08-01')));
        $this->withHeaders($headers)->getJson('/api/v1/three-d-results/live')->assertOk();

        Cache::forget('htayapi:threed-live:fresh');
        $this->bindProvider(FakeThreeDLiveProvider::throwing(new ThreeDProviderException('upstream down')));

        $this->withHeaders($headers)
            ->getJson('/api/v1/three-d-results/live')
            ->assertOk()
            ->assertJsonPath('data.three_d_live.threed', '479')
            ->assertJsonPath('data.stale', true);
    }

    /** A vendor with nothing published must not blank a previously good value. */
    public function test_an_empty_upstream_keeps_the_last_known_draw(): void
    {
        $headers = $this->tokenHeader();

        $this->bindProvider(new FakeThreeDLiveProvider(new ThreeDDraw('479', '2026-08-01')));
        $this->withHeaders($headers)->getJson('/api/v1/three-d-results/live')->assertOk();

        Cache::forget('htayapi:threed-live:fresh');
        $this->bindProvider(new FakeThreeDLiveProvider(null));

        $this->withHeaders($headers)
            ->getJson('/api/v1/three-d-results/live')
            ->assertOk()
            ->assertJsonPath('data.three_d_live.threed', '479')
            ->assertJsonPath('data.stale', true);
    }

    public function test_returns_null_when_upstream_fails_with_a_cold_cache(): void
    {
        $this->bindProvider(FakeThreeDLiveProvider::throwing(new ThreeDProviderException('upstream down')));

        $this->withHeaders($this->tokenHeader())
            ->getJson('/api/v1/three-d-results/live')
            ->assertOk()
            ->assertJsonPath('data.three_d_live', null)
            ->assertJsonPath('data.stale', true);
    }

    public function test_provider_maps_the_vendor_payload(): void
    {
        Http::fake([
            '*' => Http::response([
                'code' => 200,
                'data' => ['date' => '2026-08-01', 'threed' => '479'],
            ], 200),
        ]);

        $provider = new HtayApiThreeDLiveProvider(
            'https://htayapi.com/mm-twod/thai/3dlive',
            'test-key',
            20,
            new HtayApiCallBudget(10),
        );

        $draw = $provider->fetch();

        $this->assertNotNull($draw);
        $this->assertSame(['threed' => '479', 'stock_date' => '2026-08-01'], $draw->toArray());
    }

    /** A half-filled payload is "nothing published yet", not a fault. */
    public function test_provider_returns_null_for_an_incomplete_payload(): void
    {
        Http::fake(['*' => Http::response(['code' => 200, 'data' => ['date' => '2026-08-01']], 200)]);

        $provider = new HtayApiThreeDLiveProvider('https://htayapi.com/x', 'k', 20, new HtayApiCallBudget(10));

        $this->assertNull($provider->fetch());
    }

    public function test_provider_refuses_to_call_upstream_once_the_daily_budget_is_spent(): void
    {
        Http::fake(['*' => Http::response(['code' => 200, 'data' => []], 200)]);

        $budget = new HtayApiCallBudget(1);
        $provider = new HtayApiThreeDLiveProvider('https://htayapi.com/x', 'k', 20, $budget);

        $budget->tryConsume();

        $this->expectException(ThreeDProviderException::class);
        $provider->fetch();
    }

    public function test_provider_raises_on_a_non_2xx_response(): void
    {
        Http::fake(['*' => Http::response('nope', 503)]);

        $provider = new HtayApiThreeDLiveProvider('https://htayapi.com/x', 'k', 20, new HtayApiCallBudget(10));

        $this->expectException(ThreeDProviderException::class);
        $provider->fetch();
    }
}
