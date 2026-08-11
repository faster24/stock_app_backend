<?php

namespace Tests\Feature\ThreeDResult;

use App\Contracts\ThreeDHistoryProvider;
use App\Exceptions\ThreeDProviderException;
use App\Models\User;
use App\Services\ThreeD\HtayApiThreeDHistoryProvider;
use App\Services\TwoD\HtayApiCallBudget;
use App\Support\ThreeD\ThreeDDraw;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeThreeDHistoryProvider;
use Tests\TestCase;

class ThreeDHistoryApiTest extends TestCase
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

    private function bindProvider(ThreeDHistoryProvider $provider): void
    {
        $this->app->instance(ThreeDHistoryProvider::class, $provider);
    }

    /**
     * @return list<ThreeDDraw>
     */
    private function entries(): array
    {
        return [
            new ThreeDDraw('479', '2026-08-01'),
            new ThreeDDraw('214', '2026-07-16'),
        ];
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/three-d-results/history')->assertUnauthorized();
    }

    public function test_returns_the_history_list(): void
    {
        $this->bindProvider(new FakeThreeDHistoryProvider($this->entries()));

        $this->withHeaders($this->tokenHeader())
            ->getJson('/api/v1/three-d-results/history')
            ->assertOk()
            ->assertJsonPath('message', '3D history retrieved successfully.')
            ->assertJsonCount(2, 'data.three_d_history')
            ->assertJsonPath('data.three_d_history.0.threed', '479')
            ->assertJsonPath('data.three_d_history.0.stock_date', '2026-08-01')
            ->assertJsonPath('data.three_d_history.1.threed', '214')
            ->assertJsonPath('data.stale', false)
            ->assertJsonPath('errors', null);
    }

    /**
     * The vendor quota is shared with the 2D ticker and settlement, so a second
     * reader inside the TTL must not become a second upstream call.
     */
    public function test_a_second_request_is_served_from_cache_without_calling_upstream(): void
    {
        $provider = new FakeThreeDHistoryProvider($this->entries());
        $this->bindProvider($provider);
        $headers = $this->tokenHeader();

        $this->withHeaders($headers)->getJson('/api/v1/three-d-results/history')->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/three-d-results/history')->assertOk();

        $this->assertSame(1, $provider->calls);
    }

    /**
     * An upstream failure must still return 200 with the last known list — the
     * client renders a results page, not an error banner.
     */
    public function test_upstream_failure_degrades_to_a_stale_list_rather_than_an_error(): void
    {
        $headers = $this->tokenHeader();

        $this->bindProvider(new FakeThreeDHistoryProvider($this->entries()));
        $this->withHeaders($headers)->getJson('/api/v1/three-d-results/history')->assertOk();

        Cache::forget('htayapi:threed-history:fresh');
        $this->bindProvider(FakeThreeDHistoryProvider::throwing(new ThreeDProviderException('upstream down')));

        $this->withHeaders($headers)
            ->getJson('/api/v1/three-d-results/history')
            ->assertOk()
            ->assertJsonPath('data.three_d_history.0.threed', '479')
            ->assertJsonPath('data.stale', true);
    }

    public function test_returns_an_empty_list_when_upstream_fails_with_a_cold_cache(): void
    {
        $this->bindProvider(FakeThreeDHistoryProvider::throwing(new ThreeDProviderException('upstream down')));

        $this->withHeaders($this->tokenHeader())
            ->getJson('/api/v1/three-d-results/history')
            ->assertOk()
            ->assertJsonPath('data.three_d_history', [])
            ->assertJsonPath('data.stale', true);
    }

    public function test_provider_maps_the_vendor_payload_and_skips_incomplete_rows(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => 1,
                'message' => 'success',
                'data' => [
                    ['result' => '479', 'datetime' => '2026-08-01'],
                    ['result' => '', 'datetime' => '2026-07-16'],
                    ['datetime' => '2026-07-01'],
                    ['result' => '184', 'datetime' => '2026-06-16'],
                ],
            ], 200),
        ]);

        $provider = new HtayApiThreeDHistoryProvider(
            'https://htayapi.com/mm-twod/thai/3dhistory',
            'test-key',
            20,
            new HtayApiCallBudget(10),
        );

        $entries = $provider->fetch();

        $this->assertCount(2, $entries);
        $this->assertSame(['threed' => '479', 'stock_date' => '2026-08-01'], $entries[0]->toArray());
        $this->assertSame(['threed' => '184', 'stock_date' => '2026-06-16'], $entries[1]->toArray());
    }

    public function test_provider_refuses_to_call_upstream_once_the_daily_budget_is_spent(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $budget = new HtayApiCallBudget(1);
        $provider = new HtayApiThreeDHistoryProvider('https://htayapi.com/x', 'k', 20, $budget);

        $budget->tryConsume();

        $this->expectException(ThreeDProviderException::class);
        $provider->fetch();
    }

    public function test_provider_raises_on_a_non_2xx_response(): void
    {
        Http::fake(['*' => Http::response('nope', 503)]);

        $provider = new HtayApiThreeDHistoryProvider('https://htayapi.com/x', 'k', 20, new HtayApiCallBudget(10));

        $this->expectException(ThreeDProviderException::class);
        $provider->fetch();
    }
}
