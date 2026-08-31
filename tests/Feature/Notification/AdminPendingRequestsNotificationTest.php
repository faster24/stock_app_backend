<?php

namespace Tests\Feature\Notification;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\WithdrawalStatus;
use App\Events\DepositRequestedEvent;
use App\Events\WithdrawalRequestedEvent;
use App\Jobs\NotifyAdminsOfPendingRequestsJob;
use App\Jobs\SendNotificationJob;
use App\Models\AdminBankSetting;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\FcmToken;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminPendingRequestsNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(NotifyAdminsOfPendingRequestsJob::DEBOUNCE_CACHE_KEY);

        $this->admin = User::factory()->admin()->create();
        $this->user = User::factory()->normalUser()->create();

        FcmToken::factory()->create([
            'user_id' => $this->admin->id,
            'is_active' => true,
        ]);
    }

    private function makeDeposit(): Deposit
    {
        return Deposit::create([
            'user_id' => $this->user->id,
            'admin_bank_setting_id' => AdminBankSetting::factory()->create(['currency' => Currency::MMK])->id,
            'currency' => Currency::MMK->value,
            'claimed_amount' => 10_000,
            'status' => DepositStatus::PENDING->value,
        ]);
    }

    private function makeWithdrawal(): Withdrawal
    {
        return Withdrawal::create([
            'user_id' => $this->user->id,
            'currency' => Currency::MMK->value,
            'amount' => 10_000,
            'status' => WithdrawalStatus::PENDING->value,
            'bank_snapshot' => ['bank_name' => 'KBZ', 'account_name' => 'A', 'account_number' => '1'],
        ]);
    }

    private function makeWinningBet(): Bet
    {
        return Bet::factory()->create([
            'user_id' => $this->user->id,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => BetPayoutStatus::PENDING,
        ]);
    }

    public function test_a_new_request_schedules_one_delayed_aggregate_push(): void
    {
        Queue::fake();

        DepositRequestedEvent::dispatch($this->makeDeposit());

        Queue::assertPushed(NotifyAdminsOfPendingRequestsJob::class, 1);
        $this->assertTrue(Cache::has(NotifyAdminsOfPendingRequestsJob::DEBOUNCE_CACHE_KEY));
    }

    public function test_a_burst_of_requests_collapses_into_a_single_push(): void
    {
        Queue::fake();

        DepositRequestedEvent::dispatch($this->makeDeposit());
        DepositRequestedEvent::dispatch($this->makeDeposit());
        WithdrawalRequestedEvent::dispatch($this->makeWithdrawal());

        Queue::assertPushed(NotifyAdminsOfPendingRequestsJob::class, 1);
    }

    public function test_the_next_window_can_schedule_again_once_the_job_runs(): void
    {
        Queue::fake();

        DepositRequestedEvent::dispatch($this->makeDeposit());
        (new NotifyAdminsOfPendingRequestsJob)->handle(app(\App\Services\Admin\PendingRequestCountsService::class));

        $this->assertFalse(Cache::has(NotifyAdminsOfPendingRequestsJob::DEBOUNCE_CACHE_KEY));

        WithdrawalRequestedEvent::dispatch($this->makeWithdrawal());

        Queue::assertPushed(NotifyAdminsOfPendingRequestsJob::class, 2);
    }

    public function test_the_push_body_aggregates_every_queue_and_omits_empty_ones(): void
    {
        Queue::fake();

        $this->makeDeposit();
        $this->makeWinningBet();

        (new NotifyAdminsOfPendingRequestsJob)->handle(app(\App\Services\Admin\PendingRequestCountsService::class));

        Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) {
            return $job->user->is($this->admin)
                && $job->notificationType === 'admin_pending_requests'
                && $job->body === '1 win to pay out, 1 deposit waiting in the system.'
                && $job->data['total'] === 2;
        });
    }

    public function test_nothing_is_sent_when_the_queues_emptied_during_the_debounce_window(): void
    {
        Queue::fake();

        (new NotifyAdminsOfPendingRequestsJob)->handle(app(\App\Services\Admin\PendingRequestCountsService::class));

        Queue::assertNotPushed(SendNotificationJob::class);
    }

    public function test_admins_without_an_active_device_are_skipped(): void
    {
        Queue::fake();

        FcmToken::where('user_id', $this->admin->id)->update(['is_active' => false]);
        $this->makeDeposit();

        (new NotifyAdminsOfPendingRequestsJob)->handle(app(\App\Services\Admin\PendingRequestCountsService::class));

        Queue::assertNotPushed(SendNotificationJob::class);
    }

    public function test_non_admins_are_never_notified(): void
    {
        Queue::fake();

        FcmToken::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $this->makeDeposit();

        (new NotifyAdminsOfPendingRequestsJob)->handle(app(\App\Services\Admin\PendingRequestCountsService::class));

        Queue::assertPushed(SendNotificationJob::class, 1);
        Queue::assertPushed(
            SendNotificationJob::class,
            fn (SendNotificationJob $job) => $job->user->is($this->admin)
        );
    }
}
