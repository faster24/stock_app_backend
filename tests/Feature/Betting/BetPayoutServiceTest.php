<?php

namespace Tests\Feature\Betting;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\WalletTransactionType;
use App\Events\BetPaidOutEvent;
use App\Models\Bet;
use App\Models\TwoDResult;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Bet\BetPayoutService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BetPayoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedResult(string $historyId = 'hist-payout', string $twod = '12'): void
    {
        TwoDResult::query()->create([
            'history_id' => $historyId,
            'stock_date' => '2026-03-19',
            'stock_datetime' => '2026-03-19 11:00:00',
            'open_time' => '11:00:00',
            'twod' => $twod,
            'payload' => [],
        ]);
    }

    private function userWithWallet(int $balance = 50_000): User
    {
        $user = User::factory()->normalUser()->create();
        Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => $balance,
            'currency' => Currency::MMK,
            'currency_locked_at' => now(),
        ]);

        return $user;
    }

    private function winningBet(User $user, BetPayoutStatus $payout = BetPayoutStatus::PENDING, string $slot = '11:00:00'): Bet
    {
        $bet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => $payout,
            'target_opentime' => $slot,
            'stock_date' => '2026-03-19',
            'settled_result_history_id' => 'hist-payout',
        ]);
        $bet->betNumbers()->create(['number' => 12, 'amount' => 1000, 'potential_winning' => 85_000]);

        return $bet;
    }

    public function test_approve_credits_wallet_marks_paid_out_and_records_admin(): void
    {
        Event::fake([BetPaidOutEvent::class]);
        $admin = User::factory()->admin()->create();
        $user = $this->userWithWallet(50_000);
        $this->seedResult();
        $bet = $this->winningBet($user);

        $paid = app(BetPayoutService::class)->approve($bet, (string) $admin->id, 'REF-1', 'verified');

        $this->assertSame(BetPayoutStatus::PAID_OUT, $paid->payout_status);
        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'payout_status' => BetPayoutStatus::PAID_OUT->value,
            'paid_out_by_user_id' => $admin->id,
            'payout_reference' => 'REF-1',
            'payout_note' => 'verified',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'type' => WalletTransactionType::BET_WIN->value,
            'amount' => 85_000,
        ]);
        Event::assertDispatched(BetPaidOutEvent::class);
    }

    public function test_approve_rejects_a_non_won_bet(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->userWithWallet();
        $bet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::TWO_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::LOST,
            'payout_status' => BetPayoutStatus::PENDING,
            'target_opentime' => '11:00:00',
            'stock_date' => '2026-03-19',
        ]);

        $this->expectException(DomainException::class);
        app(BetPayoutService::class)->approve($bet, (string) $admin->id);
    }

    public function test_approve_rejects_an_already_paid_bet(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->userWithWallet();
        $this->seedResult();
        $bet = $this->winningBet($user, BetPayoutStatus::PAID_OUT);

        $this->expectException(DomainException::class);
        app(BetPayoutService::class)->approve($bet, (string) $admin->id);
    }

    public function test_approve_bulk_pays_all_won_pending_for_a_draw(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seedResult();
        $b1 = $this->winningBet($this->userWithWallet());
        $b2 = $this->winningBet($this->userWithWallet());
        $otherSlot = $this->winningBet($this->userWithWallet(), slot: '16:30:00');

        $summary = app(BetPayoutService::class)->approveBulk('2026-03-19', '11:00:00', (string) $admin->id, 'bulk run');

        $this->assertSame(2, $summary['approved']);
        $this->assertSame(170_000, $summary['total_paid']);
        $this->assertDatabaseHas('bets', ['id' => $b1->id, 'payout_status' => BetPayoutStatus::PAID_OUT->value]);
        $this->assertDatabaseHas('bets', ['id' => $b2->id, 'payout_status' => BetPayoutStatus::PAID_OUT->value]);
        $this->assertDatabaseHas('bets', ['id' => $otherSlot->id, 'payout_status' => BetPayoutStatus::PENDING->value]);
    }
}
