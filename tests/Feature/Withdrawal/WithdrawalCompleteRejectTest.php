<?php

namespace Tests\Feature\Withdrawal;

use App\Enums\Currency;
use App\Enums\WithdrawalStatus;
use App\Jobs\SendNotificationJob;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UploadsScriptableImages;
use Tests\TestCase;

class WithdrawalCompleteRejectTest extends TestCase
{
    use RefreshDatabase, UploadsScriptableImages;

    private User $admin;
    private string $adminToken;
    private User $user;
    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('bet_slips');

        $this->admin      = User::factory()->admin()->create();
        $this->adminToken = $this->admin->createToken('test')->plainTextToken;
        $this->user       = User::factory()->create();
        $this->wallet     = Wallet::factory()->create([
            'user_id'            => $this->user->id,
            'balance'            => 50_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
            'bank_name'          => 'KBZ',
            'account_name'       => 'Test User',
            'account_number'     => '1234567890',
        ]);
    }

    private function createPendingWithdrawal(int $amount = 20_000): Withdrawal
    {
        $withdrawal = Withdrawal::create([
            'user_id'       => $this->user->id,
            'currency'      => 'MMK',
            'amount'        => $amount,
            'status'        => WithdrawalStatus::PENDING->value,
            'bank_snapshot' => ['bank_name' => 'KBZ', 'account_name' => 'Test User', 'account_number' => '1234567890'],
        ]);

        // Simulate the debit that happens at creation time
        $this->wallet->update(['balance' => $this->wallet->balance - $amount]);

        return $withdrawal;
    }

    // ── Complete tests ─────────────────────────────────────────────────────────

    public function test_svg_payout_proof_is_rejected(): void
    {
        $withdrawal = $this->createPendingWithdrawal(20_000);

        $response = $this->postJson(
            "/api/v1/admin/withdrawals/{$withdrawal->id}/complete",
            // A .png name does not help: validation reads the bytes.
            ['payout_proof' => $this->scriptableSvgUpload('proof.png')],
            ['Authorization' => "Bearer {$this->adminToken}"]
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['payout_proof']]);

        $this->assertSame(WithdrawalStatus::PENDING->value, $withdrawal->fresh()->status->value);
    }

    public function test_admin_complete_attaches_proof_no_ledger_write(): void
    {
        $withdrawal = $this->createPendingWithdrawal(20_000);

        $response = $this->postJson(
            "/api/v1/admin/withdrawals/{$withdrawal->id}/complete",
            ['payout_proof' => UploadedFile::fake()->image('proof.jpg')],
            ['Authorization' => "Bearer {$this->adminToken}"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.withdrawal.status', 'COMPLETED');
        $response->assertJsonPath('data.withdrawal.payout_proof.exists', true);

        // Balance must NOT change (debit happened at creation)
        $this->wallet->refresh();
        $this->assertEquals(30_000, $this->wallet->balance);

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_complete_requires_proof_image(): void
    {
        $withdrawal = $this->createPendingWithdrawal();

        $this->postJson(
            "/api/v1/admin/withdrawals/{$withdrawal->id}/complete",
            [],
            ['Authorization' => "Bearer {$this->adminToken}"]
        )->assertStatus(422)->assertJsonStructure(['errors' => ['payout_proof']]);
    }

    public function test_cannot_complete_non_pending_withdrawal(): void
    {
        $withdrawal = $this->createPendingWithdrawal();
        $withdrawal->update(['status' => WithdrawalStatus::COMPLETED->value]);

        $this->postJson(
            "/api/v1/admin/withdrawals/{$withdrawal->id}/complete",
            ['payout_proof' => UploadedFile::fake()->image('proof.jpg')],
            ['Authorization' => "Bearer {$this->adminToken}"]
        )->assertStatus(409);
    }

    // ── Reject tests ───────────────────────────────────────────────────────────

    public function test_admin_reject_credits_balance_back(): void
    {
        $withdrawal = $this->createPendingWithdrawal(20_000);

        $response = $this->postJson(
            "/api/v1/admin/withdrawals/{$withdrawal->id}/reject",
            ['rejection_reason' => 'Account details mismatch.'],
            ['Authorization' => "Bearer {$this->adminToken}"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.withdrawal.status', 'REJECTED');

        $this->wallet->refresh();
        $this->assertEquals(50_000, $this->wallet->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'   => $this->user->id,
            'type'      => 'WITHDRAWAL_REFUND',
            'direction' => 'CREDIT',
            'amount'    => 20_000,
        ]);
    }

    public function test_reject_requires_reason(): void
    {
        $withdrawal = $this->createPendingWithdrawal();

        $this->postJson(
            "/api/v1/admin/withdrawals/{$withdrawal->id}/reject",
            ['rejection_reason' => 'no'],
            ['Authorization' => "Bearer {$this->adminToken}"]
        )->assertStatus(422);
    }

    public function test_cannot_reject_non_pending_withdrawal(): void
    {
        $withdrawal = $this->createPendingWithdrawal();
        $withdrawal->update(['status' => WithdrawalStatus::REJECTED->value]);

        $this->postJson(
            "/api/v1/admin/withdrawals/{$withdrawal->id}/reject",
            ['rejection_reason' => 'Already rejected, testing.'],
            ['Authorization' => "Bearer {$this->adminToken}"]
        )->assertStatus(409);
    }

    public function test_non_admin_cannot_complete_or_reject(): void
    {
        $withdrawal = $this->createPendingWithdrawal();
        $userToken  = $this->user->createToken('test')->plainTextToken;

        $this->postJson(
            "/api/v1/admin/withdrawals/{$withdrawal->id}/complete",
            ['payout_proof' => UploadedFile::fake()->image('proof.jpg')],
            ['Authorization' => "Bearer $userToken"]
        )->assertStatus(403);

        $this->postJson(
            "/api/v1/admin/withdrawals/{$withdrawal->id}/reject",
            ['rejection_reason' => 'some reason'],
            ['Authorization' => "Bearer $userToken"]
        )->assertStatus(403);
    }

    // ── Notification tests ─────────────────────────────────────────────────────

    public function test_completing_withdrawal_queues_push_notification_job(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $withdrawal = $this->createPendingWithdrawal(20_000);

        $this->postJson(
            "/api/v1/admin/withdrawals/{$withdrawal->id}/complete",
            ['payout_proof' => UploadedFile::fake()->image('proof.jpg')],
            ['Authorization' => "Bearer {$this->adminToken}"]
        )->assertStatus(200);

        Bus::assertDispatched(
            SendNotificationJob::class,
            fn ($job) => $job->user->id === $this->user->id
                && $job->notificationType === 'withdrawal_completed'
                && $job->data['withdrawal_id'] === $withdrawal->id
        );
    }

    public function test_rejecting_withdrawal_queues_push_notification_job(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $withdrawal = $this->createPendingWithdrawal(20_000);

        $this->postJson(
            "/api/v1/admin/withdrawals/{$withdrawal->id}/reject",
            ['rejection_reason' => 'Account details mismatch.'],
            ['Authorization' => "Bearer {$this->adminToken}"]
        )->assertStatus(200);

        Bus::assertDispatched(
            SendNotificationJob::class,
            fn ($job) => $job->user->id === $this->user->id
                && $job->notificationType === 'withdrawal_rejected'
                && $job->data['withdrawal_id'] === $withdrawal->id
        );
    }

    // ── User cancel tests ──────────────────────────────────────────────────────

    public function test_user_cancel_credits_balance_back(): void
    {
        $withdrawal = $this->createPendingWithdrawal(20_000);
        $userToken  = $this->user->createToken('test')->plainTextToken;

        $response = $this->postJson(
            "/api/v1/withdrawals/{$withdrawal->id}/cancel",
            [],
            ['Authorization' => "Bearer $userToken"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.withdrawal.status', 'REJECTED');
        $response->assertJsonPath('data.withdrawal.rejection_reason', 'Cancelled by user.');

        $this->wallet->refresh();
        $this->assertEquals(50_000, $this->wallet->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id'   => $this->user->id,
            'type'      => 'WITHDRAWAL_REFUND',
            'direction' => 'CREDIT',
            'amount'    => 20_000,
        ]);
    }

    public function test_user_cannot_cancel_non_pending(): void
    {
        $withdrawal = $this->createPendingWithdrawal();
        $withdrawal->update(['status' => WithdrawalStatus::COMPLETED->value]);
        $userToken = $this->user->createToken('test')->plainTextToken;

        $this->postJson(
            "/api/v1/withdrawals/{$withdrawal->id}/cancel",
            [],
            ['Authorization' => "Bearer $userToken"]
        )->assertStatus(409);
    }

    public function test_user_cannot_cancel_another_users_withdrawal(): void
    {
        $other      = User::factory()->create();
        $otherWallet = Wallet::factory()->create([
            'user_id'            => $other->id,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        $withdrawal = Withdrawal::create([
            'user_id'       => $other->id,
            'currency'      => 'MMK',
            'amount'        => 5_000,
            'status'        => WithdrawalStatus::PENDING->value,
            'bank_snapshot' => ['bank_name' => 'KBZ', 'account_name' => 'Other', 'account_number' => '0000000000'],
        ]);
        $userToken = $this->user->createToken('test')->plainTextToken;

        $this->postJson(
            "/api/v1/withdrawals/{$withdrawal->id}/cancel",
            [],
            ['Authorization' => "Bearer $userToken"]
        )->assertStatus(404);
    }
}
