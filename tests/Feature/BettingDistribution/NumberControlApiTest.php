<?php

namespace Tests\Feature\BettingDistribution;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Jobs\BroadcastNumberControlsJob;
use App\Models\NumberControl;
use App\Models\OddSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Bet\BetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NumberControlApiTest extends TestCase
{
    use RefreshDatabase;

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
        ], $overrides);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/v1/admin/betting-distribution/number-controls', $this->basePayload([
            'controls' => [['number' => 23, 'action' => 'close']],
        ]))->assertStatus(401);
    }

    public function test_non_admin_cannot_set_number_controls(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/betting-distribution/number-controls', $this->basePayload([
                'controls' => [['number' => 23, 'action' => 'close']],
            ]))
            ->assertStatus(403);
    }

    public function test_admin_can_close_a_number_and_broadcast_is_dispatched(): void
    {
        Queue::fake();

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson('/api/v1/admin/betting-distribution/number-controls', $this->basePayload([
                'controls' => [['number' => 23, 'action' => 'close']],
            ]))
            ->assertStatus(200)
            ->assertJsonPath('message', 'Number controls updated successfully.')
            ->assertJsonPath('data.controls_applied', 1)
            ->assertJsonPath('data.controls.0.status', 'closed');

        $this->assertDatabaseHas('number_controls', [
            'bet_type' => '2D',
            'currency' => 'MMK',
            'number' => 23,
            'target_opentime' => '16:30:00',
            'is_closed' => true,
            'sales_limit' => null,
        ]);

        Queue::assertPushed(BroadcastNumberControlsJob::class, function (BroadcastNumberControlsJob $job): bool {
            return $job->topicName() === 'number-controls-2d-mmk';
        });
    }

    public function test_admin_can_set_a_sales_limit(): void
    {
        Queue::fake();

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson('/api/v1/admin/betting-distribution/number-controls', $this->basePayload([
                'controls' => [['number' => 45, 'action' => 'limit', 'sales_limit' => 10000]],
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.controls.0.status', 'limited')
            ->assertJsonPath('data.controls.0.sales_limit', '10000.00');

        $this->assertDatabaseHas('number_controls', [
            'number' => 45,
            'is_closed' => false,
            'sales_limit' => '10000.00',
        ]);
    }

    public function test_limit_action_requires_sales_limit(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson('/api/v1/admin/betting-distribution/number-controls', $this->basePayload([
                'controls' => [['number' => 45, 'action' => 'limit']],
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['controls.0.sales_limit']]);
    }

    public function test_number_out_of_range_for_2d_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson('/api/v1/admin/betting-distribution/number-controls', $this->basePayload([
                'controls' => [['number' => 100, 'action' => 'close']],
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['controls.0.number']]);
    }

    public function test_repeated_controls_flip_a_single_row(): void
    {
        Queue::fake();
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/betting-distribution/number-controls', $this->basePayload([
                'controls' => [['number' => 23, 'action' => 'close']],
            ]))->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/betting-distribution/number-controls', $this->basePayload([
                'controls' => [['number' => 23, 'action' => 'limit', 'sales_limit' => 5000]],
            ]))->assertStatus(200);

        $this->assertSame(1, NumberControl::query()->count());
        $control = NumberControl::query()->first();
        $this->assertFalse($control->is_closed);
        $this->assertSame('5000.00', $control->sales_limit);
    }

    public function test_admin_can_reopen_numbers(): void
    {
        Queue::fake();

        NumberControl::factory()->closed()->create([
            'number' => 23,
            'target_opentime' => '16:30:00',
            'stock_date' => Carbon::now()->toDateString(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson('/api/v1/admin/betting-distribution/number-controls/reopen', $this->basePayload([
                'numbers' => [23],
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.reopened_count', 1);

        $this->assertDatabaseCount('number_controls', 0);
        Queue::assertPushed(BroadcastNumberControlsJob::class);
    }

    public function test_settled_period_returns_conflict(): void
    {
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

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson('/api/v1/admin/betting-distribution/number-controls', $this->basePayload([
                'stock_date' => $date,
                'controls' => [['number' => 23, 'action' => 'close']],
            ]))
            ->assertStatus(409);
    }

    public function test_get_number_controls_returns_sold_and_remaining(): void
    {
        Queue::fake();

        $date = Carbon::now()->toDateString();

        $this->seedOddSetting();
        $user = User::factory()->normalUser()->create();
        $this->createWalletWithBankInfo($user);

        app(BetService::class)->createForUser($user->id, [
            'bet_type' => '2D',
            'currency' => 'MMK',
            'target_opentime' => '16:30:00',
            'security_pin' => '123456',
            'bet_numbers' => [['number' => 45, 'amount' => 6000]],
        ]);

        NumberControl::factory()->limited('10000.00')->create([
            'number' => 45,
            'target_opentime' => '16:30:00',
            'stock_date' => $date,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->getJson("/api/v1/admin/betting-distribution/number-controls/{$date}/16:30:00?bet_type=2D&currency=MMK")
            ->assertStatus(200)
            ->assertJsonPath('data.total_controls', 1)
            ->assertJsonPath('data.controls.0.number', 45)
            ->assertJsonPath('data.controls.0.status', 'limited')
            ->assertJsonPath('data.controls.0.sold', '6000.00')
            ->assertJsonPath('data.controls.0.remaining', '4000.00');
    }

    public function test_distribution_payload_includes_control_fields(): void
    {
        Queue::fake();

        $date = Carbon::now()->toDateString();

        NumberControl::factory()->closed()->create([
            'number' => 23,
            'target_opentime' => '16:30:00',
            'stock_date' => $date,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->getJson("/api/v1/admin/betting-distribution/{$date}/16:30:00?bet_type=2D&currency=MMK")
            ->assertStatus(200);

        $items = collect($response->json('data.items'));
        $item = $items->firstWhere('number', 23);

        $this->assertTrue($item['has_control']);
        $this->assertTrue($item['period_16_30']['is_closed']);
        $this->assertNull($item['period_16_30']['sales_limit']);

        $other = $items->firstWhere('number', 24);
        $this->assertFalse($other['has_control']);
        $this->assertFalse($other['period_16_30']['is_closed']);
    }

    private function seedOddSetting(): void
    {
        OddSetting::query()->updateOrCreate([
            'bet_type' => BetType::TWO_D,
            'currency' => Currency::MMK,
            'user_type' => OddSettingUserType::USER,
        ], [
            'odd' => '80.00',
            'is_active' => true,
        ]);
    }

    private function createWalletWithBankInfo(User $user, int $balance = 100_000): Wallet
    {
        return Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => $balance,
            'currency' => Currency::MMK,
            'currency_locked_at' => now(),
            'bank_name' => 'KBZ',
            'account_name' => 'Test User',
            'account_number' => '1234567890',
        ]);
    }
}
