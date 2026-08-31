<?php

namespace Tests\Feature\Betting;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Enums\BetStatus;
use App\Models\Bet;
use App\Models\BetNumber;
use App\Models\OddSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Bet\BetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BetApiTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_for_user_debits_wallet_and_writes_ledger(): void
    {
        $this->seedOddSetting(BetType::TWO_D, Currency::MMK, OddSettingUserType::USER, '80.00');

        $user    = User::factory()->normalUser()->create();
        $wallet  = $this->createWalletWithBankInfo($user, 50_000);
        $service = app(BetService::class);

        $bet = $service->createForUser($user->id, [
            'bet_type'        => '2D',
            'currency'        => 'MMK',
            'target_opentime' => '11:00:00',
            'security_pin'    => '123456',
            'bet_numbers'     => [['number' => 55, 'amount' => 1000]],
        ]);

        $wallet->refresh();
        $this->assertEquals(49_000, $wallet->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'        => $user->id,
            'type'           => 'BET_PLACE',
            'direction'      => 'DEBIT',
            'amount'         => 1000,
            'reference_type' => Bet::class,
            'reference_id'   => $bet->id,
        ]);
    }

    public function test_create_for_user_rejects_a_wrong_security_pin(): void
    {
        $this->seedOddSetting(BetType::TWO_D, Currency::MMK, OddSettingUserType::USER, '80.00');

        $user    = User::factory()->normalUser()->create();
        $wallet  = $this->createWalletWithBankInfo($user, 50_000);
        $service = app(BetService::class);

        try {
            $service->createForUser($user->id, [
                'bet_type'        => '2D',
                'currency'        => 'MMK',
                'target_opentime' => '11:00:00',
                'security_pin'    => '999999',
                'bet_numbers'     => [['number' => 55, 'amount' => 1000]],
            ]);
            $this->fail('Expected ValidationException for an invalid security PIN.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('security_pin', $e->errors());
        }

        $this->assertDatabaseCount('bets', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);

        $wallet->refresh();
        $this->assertEquals(50_000, $wallet->balance);
    }

    public function test_create_for_user_rolls_back_bet_when_balance_insufficient(): void
    {
        $this->seedOddSetting(BetType::TWO_D, Currency::MMK, OddSettingUserType::USER, '80.00');

        $user   = User::factory()->normalUser()->create();
        $wallet = $this->createWalletWithBankInfo($user, 500);
        $service = app(BetService::class);

        $betsBefore = Bet::query()->count();

        try {
            $service->createForUser($user->id, [
                'bet_type'        => '2D',
                'currency'        => 'MMK',
                'target_opentime' => '11:00:00',
                'security_pin'    => '123456',
            'bet_numbers'     => [['number' => 55, 'amount' => 1000]],
            ]);
            $this->fail('Expected DomainException for insufficient balance.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }

        $this->assertSame($betsBefore, Bet::query()->count());
        $this->assertDatabaseCount('wallet_transactions', 0);

        $wallet->refresh();
        $this->assertEquals(500, $wallet->balance);
    }

    public function test_create_for_user_defaults_status_to_accepted(): void
    {
        $this->seedOddSetting(BetType::TWO_D, Currency::MMK, OddSettingUserType::USER, '80.00');

        $user   = User::factory()->normalUser()->create();
        $this->createWalletWithBankInfo($user, 50_000);
        $service = app(BetService::class);

        $bet = $service->createForUser($user->id, [
            'bet_type'        => '2D',
            'currency'        => 'MMK',
            'target_opentime' => '11:00:00',
            'security_pin'    => '123456',
            'bet_numbers'     => [['number' => 55, 'amount' => 1000]],
        ]);

        $this->assertEquals(BetStatus::ACCEPTED, $bet->status);
    }

    public function test_create_for_user_requires_complete_bank_info(): void
    {
        $this->seedOddSetting(BetType::TWO_D, Currency::MMK, OddSettingUserType::USER, '80.00');

        $user = User::factory()->normalUser()->create();
        Wallet::factory()->create([
            'user_id'            => $user->id,
            'balance'            => 50_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
            'bank_name'          => null,
            'account_name'       => null,
            'account_number'     => null,
        ]);
        $service = app(BetService::class);

        try {
            $service->createForUser($user->id, [
                'bet_type'        => '2D',
                'currency'        => 'MMK',
                'target_opentime' => '11:00:00',
                'security_pin'    => '123456',
            'bet_numbers'     => [['number' => 55, 'amount' => 1000]],
            ]);
            $this->fail('Expected missing bank info to fail service validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bank_info', $exception->errors());
        }
    }

    public function test_create_for_user_requires_wallet_currency_to_be_set(): void
    {
        $this->seedOddSetting(BetType::TWO_D, Currency::MMK, OddSettingUserType::USER, '80.00');

        $user = User::factory()->normalUser()->create();
        Wallet::factory()->create([
            'user_id'       => $user->id,
            'balance'       => 50_000,
            'currency'      => null,
            'bank_name'     => 'KBZ',
            'account_name'  => 'Test',
            'account_number'=> '1234567890',
        ]);
        $service = app(BetService::class);

        try {
            $service->createForUser($user->id, [
                'bet_type'        => '2D',
                'currency'        => 'MMK',
                'target_opentime' => '11:00:00',
                'security_pin'    => '123456',
            'bet_numbers'     => [['number' => 55, 'amount' => 1000]],
            ]);
            $this->fail('Expected missing wallet currency to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('wallet_currency', $exception->errors());
        }
    }

    private function seedOddSetting(BetType $betType, Currency $currency, OddSettingUserType $userType, string $odd): void
    {
        OddSetting::query()->updateOrCreate([
            'bet_type'  => $betType,
            'currency'  => $currency,
            'user_type' => $userType,
        ], [
            'odd'       => $odd,
            'is_active' => true,
        ]);
    }

    private function createWalletWithBankInfo(User $user, int $balance = 50_000): Wallet
    {
        return Wallet::factory()->create([
            'user_id'            => $user->id,
            'balance'            => $balance,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
            'bank_name'          => 'KBZ',
            'account_name'       => 'Test User',
            'account_number'     => '1234567890',
        ]);
    }
}
