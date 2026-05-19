<?php

namespace Tests\Feature\Deposit;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Models\AdminBankSetting;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DepositListCancelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $adminToken;
    private User $user;
    private string $userToken;
    private Wallet $wallet;
    private AdminBankSetting $bankSetting;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('bet_slips');

        $this->admin      = User::factory()->admin()->create();
        $this->adminToken = $this->admin->createToken('test')->plainTextToken;
        $this->user       = User::factory()->create();
        $this->userToken  = $this->user->createToken('test')->plainTextToken;
        $this->wallet     = Wallet::factory()->create([
            'user_id'            => $this->user->id,
            'balance'            => 0,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        $this->bankSetting = AdminBankSetting::factory()->create([
            'currency'  => Currency::MMK,
            'is_active' => true,
        ]);
    }

    private function createDeposit(string $userId, string $status = 'PENDING', int $amount = 10_000): Deposit
    {
        $deposit = Deposit::create([
            'user_id'               => $userId,
            'admin_bank_setting_id' => $this->bankSetting->id,
            'currency'              => 'MMK',
            'claimed_amount'        => $amount,
            'status'                => $status,
        ]);
        $deposit->addMedia(UploadedFile::fake()->image('proof.jpg'))->toMediaCollection('proof_of_payment');

        return $deposit;
    }

    // ── List tests ─────────────────────────────────────────────────────────────

    public function test_user_lists_only_own_deposits(): void
    {
        $other = User::factory()->create();
        Wallet::factory()->create([
            'user_id' => $other->id, 'currency' => Currency::MMK, 'currency_locked_at' => now(),
        ]);

        $this->createDeposit($this->user->id);
        $this->createDeposit($this->user->id);
        $this->createDeposit($other->id);

        $response = $this->getJson('/api/v1/deposits',
            ['Authorization' => "Bearer {$this->userToken}"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.total', 2);
    }

    public function test_admin_can_list_all_deposits(): void
    {
        $other = User::factory()->create();
        Wallet::factory()->create([
            'user_id' => $other->id, 'currency' => Currency::MMK, 'currency_locked_at' => now(),
        ]);

        $this->createDeposit($this->user->id);
        $this->createDeposit($other->id);

        $response = $this->getJson('/api/v1/admin/deposits',
            ['Authorization' => "Bearer {$this->adminToken}"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.total', 2);
    }

    public function test_admin_can_filter_by_status(): void
    {
        $this->createDeposit($this->user->id, 'PENDING');
        $this->createDeposit($this->user->id, 'APPROVED');
        $this->createDeposit($this->user->id, 'REJECTED');

        $response = $this->getJson('/api/v1/admin/deposits?status=PENDING',
            ['Authorization' => "Bearer {$this->adminToken}"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.total', 1);
    }

    public function test_user_can_show_own_deposit(): void
    {
        $deposit = $this->createDeposit($this->user->id);

        $response = $this->getJson("/api/v1/deposits/{$deposit->id}",
            ['Authorization' => "Bearer {$this->userToken}"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.deposit.id', $deposit->id);
    }

    public function test_user_cannot_show_others_deposit(): void
    {
        $other   = User::factory()->create();
        $deposit = $this->createDeposit($other->id);

        $this->getJson("/api/v1/deposits/{$deposit->id}",
            ['Authorization' => "Bearer {$this->userToken}"]
        )->assertStatus(404);
    }

    // ── Cancel tests ───────────────────────────────────────────────────────────

    public function test_user_can_cancel_own_pending_deposit(): void
    {
        $deposit = $this->createDeposit($this->user->id);

        $response = $this->postJson("/api/v1/deposits/{$deposit->id}/cancel",
            [],
            ['Authorization' => "Bearer {$this->userToken}"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.deposit.status', 'REJECTED');
        $response->assertJsonPath('data.deposit.rejection_reason', 'Cancelled by user.');

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_cancel_non_pending_deposit_returns_409(): void
    {
        $deposit = $this->createDeposit($this->user->id, 'APPROVED');

        $this->postJson("/api/v1/deposits/{$deposit->id}/cancel",
            [],
            ['Authorization' => "Bearer {$this->userToken}"]
        )->assertStatus(409);
    }

    public function test_user_cannot_cancel_another_users_deposit(): void
    {
        $other   = User::factory()->create();
        $deposit = $this->createDeposit($other->id);

        $this->postJson("/api/v1/deposits/{$deposit->id}/cancel",
            [],
            ['Authorization' => "Bearer {$this->userToken}"]
        )->assertStatus(404);
    }

    public function test_guest_cannot_access_deposit_endpoints(): void
    {
        $this->getJson('/api/v1/deposits')->assertStatus(401);
        $this->postJson('/api/v1/deposits')->assertStatus(401);
        $this->getJson('/api/v1/admin/deposits')->assertStatus(401);
    }
}
