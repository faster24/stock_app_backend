<?php

namespace Tests\Feature\Analytics;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Models\BetNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_all_analytics_endpoints(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('auth_token')->plainTextToken;
        $userA = User::factory()->normalUser()->create();
        $userB = User::factory()->normalUser()->create();

        $betA = Bet::factory()->for($userA)->create([
            'bet_type' => BetType::TWO_D,
            'target_opentime' => '11:00:00',
            'stock_date' => '2026-03-19',
            'amount' => 100,
            'total_amount' => '200.00',
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => BetPayoutStatus::PAID_OUT,
            'paid_out_at' => '2026-03-19 12:15:00',
            'paid_out_by_user_id' => $admin->id,
        ]);
        $betB = Bet::factory()->for($userB)->create([
            'bet_type' => BetType::TWO_D,
            'target_opentime' => '11:00:00',
            'stock_date' => '2026-03-19',
            'amount' => 100,
            'total_amount' => '100.00',
            'status' => BetStatus::REJECTED,
            'bet_result_status' => BetResultStatus::LOST,
            'payout_status' => BetPayoutStatus::PENDING,
        ]);
        $betC = Bet::factory()->for($userA)->create([
            'bet_type' => BetType::THREE_D,
            'target_opentime' => '15:00:00',
            'stock_date' => '2026-03-20',
            'amount' => 150,
            'total_amount' => '150.00',
            'status' => BetStatus::REFUNDED,
            'bet_result_status' => BetResultStatus::VOID,
            'payout_status' => BetPayoutStatus::PENDING,
        ]);
        $betD = Bet::factory()->for($userB)->create([
            'bet_type' => BetType::THREE_D,
            'target_opentime' => '15:00:00',
            'stock_date' => '2026-03-20',
            'amount' => 50,
            'total_amount' => '50.00',
            'status' => BetStatus::PENDING,
            'bet_result_status' => BetResultStatus::OPEN,
            'payout_status' => BetPayoutStatus::PENDING,
        ]);
        Bet::factory()->for($userA)->create([
            'stock_date' => '2026-03-21',
            'total_amount' => '999.00',
        ]);

        BetNumber::factory()->forBetWithNumber($betA, 11)->create();
        BetNumber::factory()->forBetWithNumber($betA, 22)->create();
        BetNumber::factory()->forBetWithNumber($betB, 11)->create();
        BetNumber::factory()->forBetWithNumber($betC, 22)->create();
        BetNumber::factory()->forBetWithNumber($betD, 33)->create();

        DB::table('bet_settlement_runs')->insert([
            [
                'history_id' => 'history-analytics-1',
                'two_d_result_id' => null,
                'settled_at' => '2026-03-19 12:05:00',
                'summary' => json_encode(['processed' => 4, 'won' => 1, 'lost' => 2], JSON_THROW_ON_ERROR),
                'created_at' => '2026-03-19 12:05:00',
                'updated_at' => '2026-03-19 12:05:00',
            ],
            [
                'history_id' => 'history-analytics-2',
                'two_d_result_id' => null,
                'settled_at' => null,
                'summary' => json_encode(['processed' => 2, 'won' => 0, 'lost' => 1], JSON_THROW_ON_ERROR),
                'created_at' => '2026-03-20 16:35:00',
                'updated_at' => '2026-03-20 16:35:00',
            ],
        ]);

        $rangeQuery = 'from=2026-03-19&to=2026-03-20';

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/v1/admin/analytics/kpis?'.$rangeQuery)
            ->assertOk()
            ->assertJsonPath('data.kpis.total_bets', 4)
            ->assertJsonPath('data.kpis.unique_bettors', 2)
            ->assertJsonPath('data.kpis.total_turnover', '500.00')
            ->assertJsonPath('data.kpis.accepted_count', 1)
            ->assertJsonPath('data.kpis.rejected_count', 1)
            ->assertJsonPath('data.kpis.refunded_count', 1)
            ->assertJsonPath('data.kpis.paid_out_count', 1);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/v1/admin/analytics/trends/daily?'.$rangeQuery)
            ->assertOk()
            ->assertJsonCount(2, 'data.daily_trends');

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/v1/admin/analytics/status-distribution?'.$rangeQuery)
            ->assertOk()
            ->assertJsonPath('data.status_distribution.total_bets', 4);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/v1/admin/analytics/payouts?'.$rangeQuery)
            ->assertOk()
            ->assertJsonPath('data.payouts.payout_count', 1)
            ->assertJsonPath('data.payouts.paid_out_total_amount', '200.00');

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/v1/admin/analytics/top-numbers?'.$rangeQuery.'&bet_type=2D&limit=5')
            ->assertOk()
            ->assertJsonPath('data.top_numbers.0.number', 11)
            ->assertJsonPath('data.top_numbers.0.bet_frequency', 2);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/v1/admin/analytics/settlement-runs?'.$rangeQuery)
            ->assertOk()
            ->assertJsonPath('data.settlement_runs.total_runs', 2)
            ->assertJsonPath('data.settlement_runs.summary_totals.processed', 6);
    }

    public function test_non_admin_cannot_access_analytics_endpoints(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/analytics/kpis?from=2026-03-19&to=2026-03-20')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');
    }

    public function test_analytics_endpoints_validate_required_date_range(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/analytics/kpis')
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['from', 'to'],
            ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/analytics/top-numbers?from=2026-03-20&to=2026-03-19')
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('errors.to.0', 'The to field must be a date after or equal to from.');
    }
}

