<?php

namespace Tests\Feature\Deposit;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Events\DepositApprovedEvent;
use App\Events\DepositRejectedEvent;
use App\Jobs\SendNotificationJob;
use App\Models\AdminBankSetting;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DepositApproveRejectTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    private User $user;

    private Wallet $wallet;

    private AdminBankSetting $bankSetting;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('bet_slips');

        $this->admin = User::factory()->admin()->create();
        $this->adminToken = $this->admin->createToken('test')->plainTextToken;
        $this->user = User::factory()->create();
        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'balance' => 0,
            'currency' => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        $this->bankSetting = AdminBankSetting::factory()->create([
            'currency' => Currency::MMK,
            'is_active' => true,
        ]);
    }

    private function createPendingDeposit(int $claimedAmount = 50_000): Deposit
    {
        $deposit = Deposit::create([
            'user_id' => $this->user->id,
            'admin_bank_setting_id' => $this->bankSetting->id,
            'currency' => 'MMK',
            'claimed_amount' => $claimedAmount,
            'status' => DepositStatus::PENDING->value,
        ]);
        $deposit->addMedia(UploadedFile::fake()->image('proof.jpg'))->toMediaCollection('proof_of_payment');

        return $deposit;
    }

    // ── Approve tests ──────────────────────────────────────────────────────────

    public function test_admin_approve_full_amount_credits_balance_and_writes_ledger(): void
    {
        $deposit = $this->createPendingDeposit(50_000);

        $response = $this->postJson("/api/v1/admin/deposits/{$deposit->id}/approve", [],
            ['Authorization' => "Bearer {$this->adminToken}"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.deposit.status', 'APPROVED');
        $response->assertJsonPath('data.deposit.approved_amount', 50_000);

        $this->wallet->refresh();
        $this->assertEquals(50_000, $this->wallet->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $this->user->id,
            'type' => 'DEPOSIT',
            'direction' => 'CREDIT',
            'amount' => 50_000,
        ]);
    }

    public function test_admin_partial_approve_requires_admin_note(): void
    {
        $deposit = $this->createPendingDeposit(50_000);

        $response = $this->postJson("/api/v1/admin/deposits/{$deposit->id}/approve",
            ['approved_amount' => 30_000],
            ['Authorization' => "Bearer {$this->adminToken}"]
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['admin_note']]);

        $this->wallet->refresh();
        $this->assertEquals(0, $this->wallet->balance);
    }

    public function test_admin_partial_approve_credits_approved_amount(): void
    {
        $deposit = $this->createPendingDeposit(50_000);

        $response = $this->postJson("/api/v1/admin/deposits/{$deposit->id}/approve",
            ['approved_amount' => 30_000, 'admin_note' => 'Partial — transferred less'],
            ['Authorization' => "Bearer {$this->adminToken}"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.deposit.approved_amount', 30_000);

        $this->wallet->refresh();
        $this->assertEquals(30_000, $this->wallet->balance);
    }

    public function test_cannot_approve_non_pending_deposit(): void
    {
        $deposit = $this->createPendingDeposit(50_000);
        $deposit->update(['status' => DepositStatus::APPROVED->value]);

        $response = $this->postJson("/api/v1/admin/deposits/{$deposit->id}/approve", [],
            ['Authorization' => "Bearer {$this->adminToken}"]
        );

        $response->assertStatus(409);
    }

    public function test_approve_returns_404_for_unknown_deposit(): void
    {
        $this->postJson('/api/v1/admin/deposits/00000000-0000-0000-0000-000000000000/approve', [],
            ['Authorization' => "Bearer {$this->adminToken}"]
        )->assertStatus(404);
    }

    // ── Reject tests ───────────────────────────────────────────────────────────

    public function test_admin_reject_does_not_touch_balance(): void
    {
        $deposit = $this->createPendingDeposit(50_000);

        $response = $this->postJson("/api/v1/admin/deposits/{$deposit->id}/reject",
            ['rejection_reason' => 'Invalid transfer slip.'],
            ['Authorization' => "Bearer {$this->adminToken}"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.deposit.status', 'REJECTED');

        $this->wallet->refresh();
        $this->assertEquals(0, $this->wallet->balance);

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_reject_requires_reason(): void
    {
        $deposit = $this->createPendingDeposit(50_000);

        $this->postJson("/api/v1/admin/deposits/{$deposit->id}/reject",
            ['rejection_reason' => 'no'],
            ['Authorization' => "Bearer {$this->adminToken}"]
        )->assertStatus(422);
    }

    public function test_cannot_reject_non_pending_deposit(): void
    {
        $deposit = $this->createPendingDeposit(50_000);
        $deposit->update(['status' => DepositStatus::REJECTED->value]);

        $this->postJson("/api/v1/admin/deposits/{$deposit->id}/reject",
            ['rejection_reason' => 'Already rejected.'],
            ['Authorization' => "Bearer {$this->adminToken}"]
        )->assertStatus(409);
    }

    public function test_non_admin_cannot_approve_or_reject(): void
    {
        $deposit = $this->createPendingDeposit(50_000);
        $userToken = $this->user->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/admin/deposits/{$deposit->id}/approve", [],
            ['Authorization' => "Bearer $userToken"]
        )->assertStatus(403);

        $this->postJson("/api/v1/admin/deposits/{$deposit->id}/reject",
            ['rejection_reason' => 'reason here'],
            ['Authorization' => "Bearer $userToken"]
        )->assertStatus(403);
    }

    // ── Notification tests ─────────────────────────────────────────────────────

    public function test_approving_deposit_dispatches_deposit_approved_event(): void
    {
        Event::fake([DepositApprovedEvent::class, DepositRejectedEvent::class]);

        $deposit = $this->createPendingDeposit(50_000);

        $this->postJson("/api/v1/admin/deposits/{$deposit->id}/approve", [],
            ['Authorization' => "Bearer {$this->adminToken}"]
        )->assertStatus(200);

        Event::assertDispatched(DepositApprovedEvent::class, fn ($event) => $event->deposit->id === $deposit->id);
        Event::assertNotDispatched(DepositRejectedEvent::class);
    }

    public function test_rejecting_deposit_dispatches_deposit_rejected_event(): void
    {
        Event::fake([DepositApprovedEvent::class, DepositRejectedEvent::class]);

        $deposit = $this->createPendingDeposit(50_000);

        $this->postJson("/api/v1/admin/deposits/{$deposit->id}/reject",
            ['rejection_reason' => 'Invalid transfer slip.'],
            ['Authorization' => "Bearer {$this->adminToken}"]
        )->assertStatus(200);

        Event::assertDispatched(DepositRejectedEvent::class, fn ($event) => $event->deposit->id === $deposit->id);
        Event::assertNotDispatched(DepositApprovedEvent::class);
    }

    public function test_approving_deposit_queues_push_notification_job(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $deposit = $this->createPendingDeposit(50_000);

        $this->postJson("/api/v1/admin/deposits/{$deposit->id}/approve", [],
            ['Authorization' => "Bearer {$this->adminToken}"]
        )->assertStatus(200);

        Bus::assertDispatched(
            SendNotificationJob::class,
            fn ($job) => $job->user->id === $this->user->id
                && $job->notificationType === 'deposit_approved'
                && $job->data['deposit_id'] === $deposit->id
        );
    }

    public function test_rejecting_deposit_queues_push_notification_job(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $deposit = $this->createPendingDeposit(50_000);

        $this->postJson("/api/v1/admin/deposits/{$deposit->id}/reject",
            ['rejection_reason' => 'Invalid transfer slip.'],
            ['Authorization' => "Bearer {$this->adminToken}"]
        )->assertStatus(200);

        Bus::assertDispatched(
            SendNotificationJob::class,
            fn ($job) => $job->user->id === $this->user->id
                && $job->notificationType === 'deposit_rejected'
                && $job->data['deposit_id'] === $deposit->id
        );
    }
}
