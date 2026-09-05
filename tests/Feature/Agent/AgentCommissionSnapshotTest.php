<?php

namespace Tests\Feature\Agent;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Models\Bet;
use App\Models\OddSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Bet\BetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentCommissionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bet_placed_by_an_agent_snapshots_the_rate_and_commission(): void
    {
        $this->seedOddSetting();

        $agent = User::factory()->agent('2.00')->create();
        $this->createWalletWithBankInfo($agent, 50_000);

        $bet = $this->placeBet($agent, 10_000);

        $this->assertSame('10000.00', (string) $bet->total_amount);
        $this->assertSame('2.00', (string) $bet->agent_commission_rate);
        $this->assertSame('200.00', (string) $bet->agent_commission);
    }

    public function test_a_bet_placed_by_a_normal_user_carries_no_commission(): void
    {
        $this->seedOddSetting();

        $user = User::factory()->normalUser()->create();
        $this->createWalletWithBankInfo($user, 50_000);

        $bet = $this->placeBet($user, 1_000);

        $this->assertNull($bet->agent_commission_rate);
        $this->assertNull($bet->agent_commission);
    }

    public function test_changing_the_rate_does_not_re_rate_bets_already_placed(): void
    {
        $this->seedOddSetting();

        $agent = User::factory()->agent('2.00')->create();
        $this->createWalletWithBankInfo($agent, 100_000);

        $first = $this->placeBet($agent, 10_000);

        $agent->forceFill(['commission_rate' => '3.00'])->save();

        $second = $this->placeBet($agent, 10_000);

        $this->assertSame('200.00', (string) $first->fresh()->agent_commission);
        $this->assertSame('300.00', (string) $second->agent_commission);
    }

    public function test_editing_a_bet_re_bases_the_commission_at_the_snapshotted_rate(): void
    {
        $this->seedOddSetting();

        $agent = User::factory()->agent('2.00')->create();
        $this->createWalletWithBankInfo($agent, 100_000);

        $bet = $this->placeBet($agent, 10_000);

        // The agent's current rate moves, but the bet keeps the rate it was placed at.
        $agent->forceFill(['commission_rate' => '9.00'])->save();

        $updated = app(BetService::class)->updateForUser($agent->id, $bet->id, [
            'bet_numbers' => [['number' => 55, 'amount' => 20_000]],
        ]);

        $this->assertSame('20000.00', (string) $updated->total_amount);
        $this->assertSame('2.00', (string) $updated->agent_commission_rate);
        $this->assertSame('400.00', (string) $updated->agent_commission);
    }

    private function placeBet(User $user, int $amount): Bet
    {
        return app(BetService::class)->createForUser($user->id, [
            'bet_type' => '2D',
            'currency' => 'MMK',
            'target_opentime' => '11:00:00',
            'security_pin' => '123456',
            'bet_numbers' => [['number' => 55, 'amount' => $amount]],
        ]);
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

    private function createWalletWithBankInfo(User $user, int $balance): Wallet
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
