<?php

namespace Tests\Feature\Agent;

use App\Enums\BetStatus;
use App\Models\Bet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAgentCommissionApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/admin/agent-commissions';

    public function test_guest_cannot_read_the_agent_commission_report(): void
    {
        $this->getJson(self::ENDPOINT.'?granularity=daily&from=2026-01-01&to=2026-01-31')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_non_admin_cannot_read_the_agent_commission_report(): void
    {
        $user = User::factory()->normalUser()->create();

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('auth_token')->plainTextToken)
            ->getJson(self::ENDPOINT.'?granularity=daily&from=2026-01-01&to=2026-01-31')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.');
    }

    public function test_report_rejects_an_unknown_granularity(): void
    {
        $this->withAdmin()
            ->getJson(self::ENDPOINT.'?granularity=hourly&from=2026-01-01&to=2026-01-31')
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure(['message', 'data', 'errors' => ['granularity']]);
    }

    public function test_daily_report_buckets_by_day_and_totals_the_commission(): void
    {
        $agent = User::factory()->agent('2.00')->create(['username' => 'agent_one']);

        $this->seedBet($agent, '2026-01-01', '10000.00', '200.00');
        $this->seedBet($agent, '2026-01-01', '5000.00', '100.00');
        $this->seedBet($agent, '2026-01-02', '20000.00', '400.00');

        $response = $this->withAdmin()
            ->getJson(self::ENDPOINT.'?granularity=daily&from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->assertJsonPath('message', 'Agent commission report retrieved successfully.')
            ->assertJsonPath('errors', null);

        $rows = $response->json('data.report.rows');

        $this->assertCount(2, $rows);
        $this->assertSame('2026-01-01', $rows[0]['bucket']);
        $this->assertSame('agent_one', $rows[0]['agent_username']);
        $this->assertSame(2, $rows[0]['bets_count']);
        $this->assertSame('15000.00', $rows[0]['total_stake']);
        $this->assertSame('300.00', $rows[0]['commission']);
        $this->assertSame('2.00', $rows[0]['commission_rate']);
        $this->assertSame('2026-01-02', $rows[1]['bucket']);

        $response->assertJsonPath('data.report.summary.bets_count', 3)
            ->assertJsonPath('data.report.summary.total_stake', '35000.00')
            ->assertJsonPath('data.report.summary.commission', '700.00');
    }

    public function test_weekly_and_monthly_granularity_collapse_the_same_bets(): void
    {
        $agent = User::factory()->agent('2.00')->create();

        // Both dates fall in ISO week 1 of 2026 (Mon 2025-12-29 .. Sun 2026-01-04).
        $this->seedBet($agent, '2026-01-01', '10000.00', '200.00');
        $this->seedBet($agent, '2026-01-02', '20000.00', '400.00');

        $weekly = $this->withAdmin()
            ->getJson(self::ENDPOINT.'?granularity=weekly&from=2026-01-01&to=2026-01-31')
            ->assertOk();
        $this->assertCount(1, $weekly->json('data.report.rows'));
        $this->assertSame('600.00', $weekly->json('data.report.summary.commission'));

        $monthly = $this->withAdmin()
            ->getJson(self::ENDPOINT.'?granularity=monthly&from=2026-01-01&to=2026-01-31')
            ->assertOk();
        $this->assertCount(1, $monthly->json('data.report.rows'));
        $this->assertSame('2026-01', $monthly->json('data.report.rows.0.bucket'));
    }

    public function test_rejected_and_refunded_bets_are_excluded(): void
    {
        $agent = User::factory()->agent('2.00')->create();

        $this->seedBet($agent, '2026-01-01', '10000.00', '200.00');
        $this->seedBet($agent, '2026-01-01', '10000.00', '200.00', BetStatus::REJECTED);
        $this->seedBet($agent, '2026-01-01', '10000.00', '200.00', BetStatus::REFUNDED);

        $this->withAdmin()
            ->getJson(self::ENDPOINT.'?granularity=daily&from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->assertJsonPath('data.report.summary.bets_count', 1)
            ->assertJsonPath('data.report.summary.commission', '200.00');
    }

    public function test_one_agent_totals_never_include_another_agents_bets(): void
    {
        $agent = User::factory()->agent('2.00')->create(['username' => 'agent_one']);
        $other = User::factory()->agent('5.00')->create(['username' => 'agent_two']);
        $plainUser = User::factory()->normalUser()->create();

        $this->seedBet($agent, '2026-01-01', '10000.00', '200.00');
        $this->seedBet($other, '2026-01-01', '10000.00', '500.00');
        $this->seedBet($plainUser, '2026-01-01', '10000.00', null);

        $this->withAdmin()
            ->getJson(self::ENDPOINT.'?granularity=daily&from=2026-01-01&to=2026-01-31&agent_id='.$agent->id)
            ->assertOk()
            ->assertJsonPath('data.report.summary.bets_count', 1)
            ->assertJsonPath('data.report.summary.commission', '200.00');

        // Unfiltered: both agents, never the plain user's bet.
        $all = $this->withAdmin()
            ->getJson(self::ENDPOINT.'?granularity=daily&from=2026-01-01&to=2026-01-31')
            ->assertOk();

        $this->assertCount(2, $all->json('data.report.rows'));
        $this->assertSame('700.00', $all->json('data.report.summary.commission'));
    }

    public function test_a_mid_period_rate_change_stays_one_row_with_an_effective_rate(): void
    {
        $agent = User::factory()->agent('2.00')->create(['username' => 'agent_one']);

        $this->seedBetAtRate($agent, '2026-01-01', '10000.00', '2.00', '200.00');
        $this->seedBetAtRate($agent, '2026-01-01', '10000.00', '3.00', '300.00');

        $response = $this->withAdmin()
            ->getJson(self::ENDPOINT.'?granularity=daily&from=2026-01-01&to=2026-01-31')
            ->assertOk();

        $rows = $response->json('data.report.rows');

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['bets_count']);
        $this->assertSame('20000.00', $rows[0]['total_stake']);
        $this->assertSame('500.00', $rows[0]['commission']);
        // 500 earned on 20,000 staked.
        $this->assertSame('2.50', $rows[0]['commission_rate']);
    }

    public function test_export_streams_a_csv(): void
    {
        $agent = User::factory()->agent('2.00')->create(['username' => 'agent_one']);
        $this->seedBet($agent, '2026-01-01', '10000.00', '200.00');

        $response = $this->withAdmin()
            ->get(self::ENDPOINT.'/export?granularity=daily&from=2026-01-01&to=2026-01-31')
            ->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        // fputcsv quotes any field containing a space, hence "Rate %".
        $this->assertStringContainsString('Period,Agent,Bets,Stake,"Rate %",Commission', $csv);
        $this->assertStringContainsString('2026-01-01,agent_one,1,10000.00,2.00,200.00', $csv);
    }

    private function withAdmin(): static
    {
        $admin = User::factory()->admin()->create();

        return $this->withHeader(
            'Authorization',
            'Bearer '.$admin->createToken('auth_token')->plainTextToken
        );
    }

    private function seedBetAtRate(
        User $user,
        string $stockDate,
        string $totalAmount,
        string $rate,
        string $commission
    ): Bet {
        return Bet::factory()->create([
            'user_id' => $user->id,
            'stock_date' => $stockDate,
            'target_opentime' => '12:01:00',
            'total_amount' => $totalAmount,
            'agent_commission_rate' => $rate,
            'agent_commission' => $commission,
            'status' => BetStatus::ACCEPTED,
        ]);
    }

    private function seedBet(
        User $user,
        string $stockDate,
        string $totalAmount,
        ?string $commission,
        BetStatus $status = BetStatus::ACCEPTED
    ): Bet {
        return Bet::factory()->create([
            'user_id' => $user->id,
            'stock_date' => $stockDate,
            'target_opentime' => '12:01:00',
            'total_amount' => $totalAmount,
            'agent_commission_rate' => $commission === null ? null : $user->commission_rate,
            'agent_commission' => $commission,
            'status' => $status,
        ]);
    }
}
