<?php

namespace Tests\Feature\Withdrawal;

use App\Enums\Currency;
use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WithdrawalListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $adminToken;
    private User $user;
    private string $userToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin      = User::factory()->admin()->create();
        $this->adminToken = $this->admin->createToken('test')->plainTextToken;
        $this->user       = User::factory()->create();
        $this->userToken  = $this->user->createToken('test')->plainTextToken;
    }

    private function createWithdrawal(string $userId, string $status = 'PENDING'): Withdrawal
    {
        return Withdrawal::create([
            'user_id'       => $userId,
            'currency'      => 'MMK',
            'amount'        => 5_000,
            'status'        => $status,
            'bank_snapshot' => ['bank_name' => 'KBZ', 'account_name' => 'Test', 'account_number' => '0000000000'],
        ]);
    }

    public function test_user_lists_only_own_withdrawals(): void
    {
        $other = User::factory()->create();

        $this->createWithdrawal($this->user->id);
        $this->createWithdrawal($this->user->id);
        $this->createWithdrawal($other->id);

        $response = $this->getJson('/api/v1/withdrawals',
            ['Authorization' => "Bearer {$this->userToken}"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.total', 2);
    }

    public function test_admin_can_list_all_withdrawals(): void
    {
        $other = User::factory()->create();
        $this->createWithdrawal($this->user->id);
        $this->createWithdrawal($other->id);

        $response = $this->getJson('/api/v1/admin/withdrawals',
            ['Authorization' => "Bearer {$this->adminToken}"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.total', 2);
    }

    public function test_admin_can_filter_by_status(): void
    {
        $this->createWithdrawal($this->user->id, 'PENDING');
        $this->createWithdrawal($this->user->id, 'COMPLETED');
        $this->createWithdrawal($this->user->id, 'REJECTED');

        $response = $this->getJson('/api/v1/admin/withdrawals?status=PENDING',
            ['Authorization' => "Bearer {$this->adminToken}"]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.pagination.total', 1);
    }

    public function test_admin_can_filter_withdrawals_by_user_id(): void
    {
        $other = User::factory()->create();

        $this->createWithdrawal($this->user->id, 'PENDING');
        $this->createWithdrawal($this->user->id, 'COMPLETED');
        $this->createWithdrawal($other->id, 'PENDING');

        $this->getJson("/api/v1/admin/withdrawals?user_id={$this->user->id}",
            ['Authorization' => "Bearer {$this->adminToken}"]
        )
            ->assertStatus(200)
            ->assertJsonPath('data.pagination.total', 2);

        $this->getJson("/api/v1/admin/withdrawals?user_id={$this->user->id}&status=PENDING",
            ['Authorization' => "Bearer {$this->adminToken}"]
        )
            ->assertStatus(200)
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_user_can_show_own_withdrawal(): void
    {
        $withdrawal = $this->createWithdrawal($this->user->id);

        $this->getJson("/api/v1/withdrawals/{$withdrawal->id}",
            ['Authorization' => "Bearer {$this->userToken}"]
        )->assertStatus(200)->assertJsonPath('data.withdrawal.id', $withdrawal->id);
    }

    public function test_user_cannot_show_others_withdrawal(): void
    {
        $other      = User::factory()->create();
        $withdrawal = $this->createWithdrawal($other->id);

        $this->getJson("/api/v1/withdrawals/{$withdrawal->id}",
            ['Authorization' => "Bearer {$this->userToken}"]
        )->assertStatus(404);
    }

    public function test_non_admin_cannot_access_admin_withdrawal_list(): void
    {
        $this->getJson('/api/v1/admin/withdrawals',
            ['Authorization' => "Bearer {$this->userToken}"]
        )->assertStatus(403);
    }

    public function test_guest_cannot_access_withdrawal_endpoints(): void
    {
        $this->getJson('/api/v1/withdrawals')->assertStatus(401);
        $this->postJson('/api/v1/withdrawals')->assertStatus(401);
        $this->getJson('/api/v1/admin/withdrawals')->assertStatus(401);
    }

    public function test_admin_list_carries_payout_proof_on_every_item(): void
    {
        Storage::fake('bet_slips');

        $pending   = $this->createWithdrawal($this->user->id);
        $completed = $this->createWithdrawal($this->user->id, 'COMPLETED');
        $completed->addMedia(UploadedFile::fake()->image('proof.jpg'))->toMediaCollection('payout_proof');

        $response = $this->getJson('/api/v1/admin/withdrawals',
            ['Authorization' => "Bearer {$this->adminToken}"]
        )->assertStatus(200);

        // The dashboard's detail drawer reads payout_proof.exists on completed
        // rows; a missing key blanked the whole page.
        $items = collect($response->json('data.withdrawals'))->keyBy('id');

        foreach ($items as $item) {
            $this->assertSame(
                ['exists', 'download_url', 'file_name', 'mime_type', 'size'],
                array_keys($item['payout_proof']),
            );
            $this->assertArrayHasKey('user', $item);
        }

        $this->assertTrue($items[$completed->id]['payout_proof']['exists']);
        $this->assertFalse($items[$pending->id]['payout_proof']['exists']);
    }
}
