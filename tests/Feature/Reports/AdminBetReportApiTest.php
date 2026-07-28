<?php

namespace Tests\Feature\Reports;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBetReportApiTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return User::factory()->admin()->create()->createToken('auth_token')->plainTextToken;
    }

    private function insertBetWin(Bet $bet, int $amount): void
    {
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $bet->user_id],
            Wallet::factory()->make(['user_id' => $bet->user_id])->getAttributes()
        );

        DB::table('wallet_transactions')->insert([
            'id' => (string) Str::uuid(),
            'wallet_id' => $wallet->id,
            'user_id' => $bet->user_id,
            'type' => 'BET_WIN',
            'direction' => 'CREDIT',
            'amount' => $amount,
            'balance_after' => $amount,
            'currency' => 'MMK',
            'reference_type' => Bet::class,
            'reference_id' => $bet->id,
            'created_by_user_id' => $bet->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_daily_report_groups_by_date_with_correct_money_semantics(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->normalUser()->create();

        // Bucketing uses stock_date (a plain DATE already in Yangon business terms),
        // so no timezone conversion is involved anywhere in the report.
        $won = Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'target_opentime' => '12:01:00',
            'stock_date' => '2026-06-01',
            'total_amount' => '500.00',
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => BetPayoutStatus::PAID_OUT,
        ]);
        $this->insertBetWin($won, 300);

        Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'target_opentime' => '16:30:00',
            'stock_date' => '2026-06-01',
            'total_amount' => '200.00',
            'status' => BetStatus::PENDING,
            'bet_result_status' => BetResultStatus::OPEN,
        ]);
        Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'target_opentime' => '12:01:00',
            'stock_date' => '2026-06-01',
            'total_amount' => '150.00',
            'status' => BetStatus::REJECTED,
            'bet_result_status' => BetResultStatus::INVALID,
        ]);
        Bet::factory()->for($user)->create([
            'bet_type' => BetType::THREE_D,
            'target_opentime' => '15:00:00',
            'stock_date' => '2026-06-02',
            'total_amount' => '100.00',
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::LOST,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/reports/bets?granularity=daily&from=2026-06-01&to=2026-06-30')
            ->assertOk()
            ->assertJsonCount(2, 'data.report.rows');

        $response
            ->assertJsonPath('data.report.rows.0.bucket', '2026-06-01')
            ->assertJsonPath('data.report.rows.0.bet_count', 3)
            ->assertJsonPath('data.report.rows.0.win_count', 1)
            // money_in counts only the ACCEPTED bet (500), not PENDING (200) or REJECTED (150)
            ->assertJsonPath('data.report.rows.0.money_in', '500.00')
            ->assertJsonPath('data.report.rows.0.money_out', '300.00')
            ->assertJsonPath('data.report.rows.0.profit', '200.00')
            ->assertJsonPath('data.report.rows.1.bucket', '2026-06-02')
            ->assertJsonPath('data.report.rows.1.money_in', '100.00')
            ->assertJsonPath('data.report.rows.1.money_out', '0.00')
            ->assertJsonPath('data.report.summary.bet_count', 4)
            ->assertJsonPath('data.report.summary.money_in', '600.00')
            ->assertJsonPath('data.report.summary.money_out', '300.00')
            ->assertJsonPath('data.report.summary.profit', '300.00');
    }

    public function test_monthly_report_buckets_across_month_boundary(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->normalUser()->create();

        foreach (['2026-01-31', '2026-02-01'] as $date) {
            Bet::factory()->for($user)->create([
                'stock_date' => $date,
                'total_amount' => '100.00',
                'status' => BetStatus::ACCEPTED,
            ]);
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/reports/bets?granularity=monthly&from=2026-01-01&to=2026-02-28')
            ->assertOk()
            ->assertJsonCount(2, 'data.report.rows')
            ->assertJsonPath('data.report.rows.0.bucket', '2026-01')
            ->assertJsonPath('data.report.rows.1.bucket', '2026-02');
    }

    public function test_yearly_report_buckets_across_year_boundary(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->normalUser()->create();

        foreach (['2025-12-31', '2026-01-01'] as $date) {
            Bet::factory()->for($user)->create([
                'stock_date' => $date,
                'total_amount' => '100.00',
                'status' => BetStatus::ACCEPTED,
            ]);
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/reports/bets?granularity=yearly&from=2025-01-01&to=2026-12-31')
            ->assertOk()
            ->assertJsonCount(2, 'data.report.rows')
            ->assertJsonPath('data.report.rows.0.bucket', '2025')
            ->assertJsonPath('data.report.rows.1.bucket', '2026');
    }

    public function test_report_breaks_down_by_bet_type_and_session(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->normalUser()->create();

        Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'target_opentime' => '12:01:00',
            'stock_date' => '2026-06-01',
            'total_amount' => '300.00',
            'status' => BetStatus::ACCEPTED,
        ]);
        Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'target_opentime' => '16:30:00',
            'stock_date' => '2026-06-01',
            'total_amount' => '200.00',
            'status' => BetStatus::ACCEPTED,
        ]);
        // 2D bet outside both report sessions: counts toward 2D totals, no session bucket
        Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'target_opentime' => '11:00:00',
            'stock_date' => '2026-06-01',
            'total_amount' => '50.00',
            'status' => BetStatus::ACCEPTED,
        ]);
        Bet::factory()->for($user)->create([
            'bet_type' => BetType::THREE_D,
            'target_opentime' => '15:00:00',
            'stock_date' => '2026-06-01',
            'total_amount' => '400.00',
            'status' => BetStatus::ACCEPTED,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/reports/bets?granularity=daily&from=2026-06-01&to=2026-06-01')
            ->assertOk()
            ->assertJsonPath('data.report.rows.0.by_bet_type.2D.bet_count', 3)
            ->assertJsonPath('data.report.rows.0.by_bet_type.2D.money_in', '550.00')
            ->assertJsonPath('data.report.rows.0.by_bet_type.3D.bet_count', 1)
            ->assertJsonPath('data.report.rows.0.by_bet_type.3D.money_in', '400.00')
            ->assertJsonPath('data.report.rows.0.by_session.12:01:00.money_in', '300.00')
            ->assertJsonPath('data.report.rows.0.by_session.16:30:00.money_in', '200.00');
    }

    public function test_bet_type_filter_excludes_other_type_everywhere(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->normalUser()->create();

        Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'target_opentime' => '12:01:00',
            'stock_date' => '2026-06-01',
            'total_amount' => '300.00',
            'status' => BetStatus::ACCEPTED,
        ]);
        Bet::factory()->for($user)->create([
            'bet_type' => BetType::THREE_D,
            'target_opentime' => '15:00:00',
            'stock_date' => '2026-06-01',
            'total_amount' => '400.00',
            'status' => BetStatus::ACCEPTED,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/reports/bets?granularity=daily&from=2026-06-01&to=2026-06-01&bet_type=3D')
            ->assertOk()
            ->assertJsonPath('data.report.summary.bet_count', 1)
            ->assertJsonPath('data.report.summary.money_in', '400.00')
            ->assertJsonPath('data.report.rows.0.by_bet_type.2D.bet_count', 0);
    }

    public function test_validation_rejects_bad_input(): void
    {
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/reports/bets?from=2026-06-01&to=2026-06-30')
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/reports/bets?granularity=weekly&from=2026-06-01&to=2026-06-30')
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/reports/bets?granularity=daily&from=2026-06-30&to=2026-06-01')
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/reports/bets?granularity=daily&from=2024-01-01&to=2026-06-01')
            ->assertStatus(422);
    }

    public function test_report_requires_admin_role(): void
    {
        $user = User::factory()->normalUser()->create();
        $userToken = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$userToken)
            ->getJson('/api/v1/admin/reports/bets?granularity=daily&from=2026-06-01&to=2026-06-30')
            ->assertForbidden();

        // The role middleware responds 403 for missing tokens as well.
        $this->getJson('/api/v1/admin/reports/bets?granularity=daily&from=2026-06-01&to=2026-06-30')
            ->assertForbidden();
    }

    public function test_csv_export_streams_report(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->normalUser()->create();

        Bet::factory()->for($user)->create([
            'stock_date' => '2026-06-01',
            'total_amount' => '500.00',
            'status' => BetStatus::ACCEPTED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/admin/reports/bets/export?granularity=monthly&from=2026-06-01&to=2026-06-30');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'bet-report-monthly-2026-06-01-to-2026-06-30.csv',
            (string) $response->headers->get('content-disposition')
        );

        $content = $response->streamedContent();
        $this->assertStringContainsString('Period,Bets,Wins,"Money In","Money Out",Profit', $content);
        $this->assertStringContainsString('2026-06,1,0,500.00,0.00,500.00', $content);
    }
}
