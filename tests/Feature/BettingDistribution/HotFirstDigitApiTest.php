<?php

namespace Tests\Feature\BettingDistribution;

use App\Jobs\BroadcastNumberControlsJob;
use App\Models\DigitControl;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HotFirstDigitApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/admin/betting-distribution/hot-first-digits';

    private function adminToken(): string
    {
        return User::factory()->admin()->create()->createToken('auth_token')->plainTextToken;
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'target_opentime' => '16:30:00',
            'stock_date' => Carbon::now()->toDateString(),
            'bet_type' => '2D',
            'currency' => 'MMK',
            'digits' => [1, 2, 3],
        ], $overrides);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson(self::ENDPOINT, $this->basePayload())->assertStatus(401);
    }

    public function test_non_admin_cannot_set_hot_digits(): void
    {
        $token = User::factory()->normalUser()->create()->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT, $this->basePayload())
            ->assertStatus(403);
    }

    public function test_admin_can_set_hot_digits_and_broadcast_is_dispatched(): void
    {
        Queue::fake();

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson(self::ENDPOINT, $this->basePayload())
            ->assertStatus(200)
            ->assertJsonPath('data.hot_first_digits', ['1', '2', '3']);

        $this->assertDatabaseCount('digit_controls', 3);
        Queue::assertPushed(BroadcastNumberControlsJob::class);
    }

    public function test_a_post_replaces_the_whole_period_set(): void
    {
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT, $this->basePayload(['digits' => [1, 2, 3]]))
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT, $this->basePayload(['digits' => [2]]))
            ->assertStatus(200)
            ->assertJsonPath('data.hot_first_digits', ['2']);

        $this->assertDatabaseCount('digit_controls', 1);
        $this->assertDatabaseHas('digit_controls', ['digit' => 2]);
    }

    public function test_an_empty_array_clears_every_hot_digit(): void
    {
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT, $this->basePayload())
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT, $this->basePayload(['digits' => []]))
            ->assertStatus(200)
            ->assertJsonPath('data.hot_first_digits', []);

        $this->assertDatabaseCount('digit_controls', 0);
    }

    public function test_setting_the_same_digits_twice_is_idempotent(): void
    {
        $token = $this->adminToken();

        foreach ([1, 2] as $_) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson(self::ENDPOINT, $this->basePayload(['digits' => [4, 5]]))
                ->assertStatus(200);
        }

        $this->assertDatabaseCount('digit_controls', 2);
    }

    public function test_digits_are_validated(): void
    {
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT, $this->basePayload(['digits' => [10]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('digits.0');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT, $this->basePayload(['digits' => [1, 1]]))
            ->assertStatus(422);

        $payload = $this->basePayload();
        unset($payload['digits']);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('digits');
    }

    public function test_three_d_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson(self::ENDPOINT, $this->basePayload(['bet_type' => '3D']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('bet_type');
    }

    public function test_admin_can_read_back_the_period_set(): void
    {
        $token = $this->adminToken();
        $date = Carbon::now()->toDateString();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT, $this->basePayload(['digits' => [7, 0]]))
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT."/{$date}/16:30:00?bet_type=2D&currency=MMK")
            ->assertStatus(200)
            ->assertJsonPath('data.hot_first_digits', ['0', '7']);
    }

    public function test_hot_digits_do_not_leak_across_periods(): void
    {
        $token = $this->adminToken();
        $date = Carbon::now()->toDateString();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT, $this->basePayload(['digits' => [3]]))
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT."/{$date}/12:01:00?bet_type=2D&currency=MMK")
            ->assertStatus(200)
            ->assertJsonPath('data.hot_first_digits', []);
    }

    public function test_a_settled_period_cannot_be_modified(): void
    {
        $token = $this->adminToken();
        $date = Carbon::now()->toDateString();

        $twoDResultId = DB::table('two_d_results')->insertGetId([
            'history_id' => 'test-history-1',
            'stock_date' => $date,
            'open_time' => '16:30:00',
            'twod' => '23',
            'payload' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bet_settlement_runs')->insert([
            'history_id' => 'test-history-1',
            'two_d_result_id' => $twoDResultId,
            'settled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT, $this->basePayload())
            ->assertStatus(409)
            ->assertJsonStructure(['errors' => ['period']]);

        $this->assertDatabaseCount('digit_controls', 0);
    }

    public function test_created_by_records_the_acting_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT, $this->basePayload(['digits' => [5]]))
            ->assertStatus(200);

        $this->assertSame($admin->id, DigitControl::query()->first()->created_by);
    }
}
