<?php

namespace Tests\Feature\Deposit;

use App\Enums\Currency;
use App\Models\AdminBankSetting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UploadsScriptableImages;
use Tests\TestCase;

class DepositCreateTest extends TestCase
{
    use RefreshDatabase, UploadsScriptableImages;

    private User $user;
    private string $token;
    private Wallet $wallet;
    private AdminBankSetting $bankSetting;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('bet_slips');

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;
        $this->wallet = Wallet::factory()->create([
            'user_id'            => $this->user->id,
            'balance'            => 100_000,
            'currency'           => Currency::MMK,
            'currency_locked_at' => now(),
        ]);
        $this->bankSetting = AdminBankSetting::factory()->create([
            'currency'  => Currency::MMK,
            'is_active' => true,
        ]);
    }

    public function test_user_can_submit_deposit_with_proof(): void
    {
        $response = $this->postJson('/api/v1/deposits', [
            'admin_bank_setting_id' => $this->bankSetting->id,
            'currency'              => 'MMK',
            'claimed_amount'        => 50_000,
            'transfer_note'         => 'ref-1234',
            'proof_image'           => UploadedFile::fake()->image('proof.jpg'),
        ], ['Authorization' => "Bearer {$this->token}"]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.deposit.status', 'PENDING');
        $response->assertJsonPath('data.deposit.claimed_amount', 50_000);
        $response->assertJsonPath('data.deposit.currency', 'MMK');
        $response->assertJsonPath('data.deposit.proof_image.exists', true);

        $this->assertDatabaseHas('deposits', [
            'user_id'       => $this->user->id,
            'claimed_amount' => 50_000,
            'status'        => 'PENDING',
        ]);
    }

    public function test_svg_proof_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/deposits', [
            'admin_bank_setting_id' => $this->bankSetting->id,
            'currency'              => 'MMK',
            'claimed_amount'        => 50_000,
            // A .png name does not help: validation reads the bytes.
            'proof_image'           => $this->scriptableSvgUpload('receipt.png'),
        ], ['Authorization' => "Bearer {$this->token}"]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['proof_image']]);

        $this->assertDatabaseMissing('deposits', ['user_id' => $this->user->id]);
    }

    public function test_requires_wallet_currency_set(): void
    {
        $userNoCurrency = User::factory()->create();
        Wallet::factory()->create([
            'user_id'  => $userNoCurrency->id,
            'currency' => null,
        ]);
        $token = $userNoCurrency->createToken('test')->plainTextToken;

        $response = $this->postJson('/api/v1/deposits', [
            'admin_bank_setting_id' => $this->bankSetting->id,
            'currency'              => 'MMK',
            'claimed_amount'        => 1_000,
            'proof_image'           => UploadedFile::fake()->image('proof.jpg'),
        ], ['Authorization' => "Bearer $token"]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['wallet_currency']]);
    }

    public function test_rejects_currency_mismatch_with_wallet(): void
    {
        $thbSetting = AdminBankSetting::factory()->create([
            'currency'  => Currency::THB,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/deposits', [
            'admin_bank_setting_id' => $thbSetting->id,
            'currency'              => 'THB',
            'claimed_amount'        => 1_000,
            'proof_image'           => UploadedFile::fake()->image('proof.jpg'),
        ], ['Authorization' => "Bearer {$this->token}"]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['currency']]);
    }

    public function test_proof_image_required(): void
    {
        $response = $this->postJson('/api/v1/deposits', [
            'admin_bank_setting_id' => $this->bankSetting->id,
            'currency'              => 'MMK',
            'claimed_amount'        => 1_000,
        ], ['Authorization' => "Bearer {$this->token}"]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['proof_image']]);
    }

    public function test_claimed_amount_must_be_positive(): void
    {
        $response = $this->postJson('/api/v1/deposits', [
            'admin_bank_setting_id' => $this->bankSetting->id,
            'currency'              => 'MMK',
            'claimed_amount'        => 0,
            'proof_image'           => UploadedFile::fake()->image('proof.jpg'),
        ], ['Authorization' => "Bearer {$this->token}"]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['claimed_amount']]);
    }

    public function test_user_can_have_multiple_pending_deposits(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/deposits', [
                'admin_bank_setting_id' => $this->bankSetting->id,
                'currency'              => 'MMK',
                'claimed_amount'        => 1_000,
                'proof_image'           => UploadedFile::fake()->image('proof.jpg'),
            ], ['Authorization' => "Bearer {$this->token}"])->assertStatus(201);
        }

        $this->assertDatabaseCount('deposits', 3);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/v1/deposits', [])->assertStatus(401);
    }
}
