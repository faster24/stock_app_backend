<?php

namespace Tests\Feature\Betting;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Models\Bet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BetAdminPayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_pay_out_winning_accepted_bet_with_payout_proof(): void
    {
        Storage::fake('bet_slips');
        config(['media-library.disk_name' => 'bet_slips']);

        $owner = User::factory()->normalUser()->create();
        $bet = Bet::factory()->for($owner)->create([
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => BetPayoutStatus::PENDING,
        ]);

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/admin/bets/'.$bet->id.'/payout', [
                'payout_proof_image' => UploadedFile::fake()->image('payout.jpg'),
                'payout_reference' => 'ref-123',
                'payout_note' => 'paid via bank transfer',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Bet paid out successfully.')
            ->assertJsonPath('data.bet.id', $bet->id)
            ->assertJsonPath('data.bet.payout_status', BetPayoutStatus::PAID_OUT->value)
            ->assertJsonPath('data.bet.payout_proof.exists', true);

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'payout_status' => BetPayoutStatus::PAID_OUT->value,
            'paid_out_by_user_id' => $admin->id,
            'payout_reference' => 'ref-123',
        ]);
    }

    public function test_admin_can_refund_non_paid_out_bet_with_proof(): void
    {
        Storage::fake('bet_slips');
        config(['media-library.disk_name' => 'bet_slips']);

        $owner = User::factory()->normalUser()->create();
        $bet = Bet::factory()->for($owner)->create([
            'status' => BetStatus::REJECTED,
            'bet_result_status' => BetResultStatus::LOST,
            'payout_status' => BetPayoutStatus::PENDING,
        ]);

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/admin/bets/'.$bet->id.'/refund', [
                'payout_proof_image' => UploadedFile::fake()->image('payout.jpg'),
                'payout_reference' => 'ref-xyz',
                'payout_note' => 'returned to player wallet',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Bet refunded successfully.')
            ->assertJsonPath('data.bet.id', $bet->id)
            ->assertJsonPath('data.bet.payout_status', BetPayoutStatus::REFUNDED->value)
            ->assertJsonPath('data.bet.payout_proof.exists', true);

        $this->assertDatabaseHas('bets', [
            'id' => $bet->id,
            'payout_status' => BetPayoutStatus::REFUNDED->value,
            'paid_out_by_user_id' => $admin->id,
            'payout_reference' => 'ref-xyz',
        ]);
    }

    public function test_admin_refund_is_rejected_when_bet_is_already_paid(): void
    {
        Storage::fake('bet_slips');
        config(['media-library.disk_name' => 'bet_slips']);

        $owner = User::factory()->normalUser()->create();
        $bet = Bet::factory()->for($owner)->create([
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => BetPayoutStatus::PAID_OUT,
        ]);

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/admin/bets/'.$bet->id.'/refund', [
                'payout_proof_image' => UploadedFile::fake()->image('payout.jpg'),
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Bet is already paid out.');
    }

    public function test_admin_refund_is_rejected_when_bet_is_already_refunded(): void
    {
        Storage::fake('bet_slips');
        config(['media-library.disk_name' => 'bet_slips']);

        $owner = User::factory()->normalUser()->create();
        $bet = Bet::factory()->for($owner)->create([
            'status' => BetStatus::PENDING,
            'bet_result_status' => BetResultStatus::OPEN,
            'payout_status' => BetPayoutStatus::REFUNDED,
        ]);

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/admin/bets/'.$bet->id.'/refund', [
                'payout_proof_image' => UploadedFile::fake()->image('payout.jpg'),
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Bet refund is already recorded.');
    }

    public function test_admin_payout_is_rejected_when_bet_is_already_paid(): void
    {
        Storage::fake('bet_slips');
        config(['media-library.disk_name' => 'bet_slips']);

        $owner = User::factory()->normalUser()->create();
        $bet = Bet::factory()->for($owner)->create([
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => BetPayoutStatus::PAID_OUT,
        ]);

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/admin/bets/'.$bet->id.'/payout', [
                'payout_proof_image' => UploadedFile::fake()->image('payout.jpg'),
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Bet is already paid out.');
    }

    public function test_admin_payout_is_rejected_when_bet_is_not_won(): void
    {
        Storage::fake('bet_slips');
        config(['media-library.disk_name' => 'bet_slips']);

        $owner = User::factory()->normalUser()->create();
        $bet = Bet::factory()->for($owner)->create([
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::LOST,
            'payout_status' => BetPayoutStatus::PENDING,
        ]);

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/admin/bets/'.$bet->id.'/payout', [
                'payout_proof_image' => UploadedFile::fake()->image('payout.jpg'),
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Bet is not eligible for payout.');
    }

    public function test_owner_can_download_payout_proof_and_non_owner_gets_404(): void
    {
        Storage::fake('bet_slips');
        config(['media-library.disk_name' => 'bet_slips']);

        $owner = User::factory()->normalUser()->create();
        $bet = Bet::factory()->for($owner)->create([
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::WON,
            'payout_status' => BetPayoutStatus::PENDING,
        ]);

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/admin/bets/'.$bet->id.'/payout', [
                'payout_proof_image' => UploadedFile::fake()->image('payout.jpg'),
            ])
            ->assertOk();

        $ownerToken = $owner->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->get('/api/v1/bets/'.$bet->id.'/payout-proof')
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $nonOwner = User::factory()->normalUser()->create();
        Sanctum::actingAs($nonOwner);

        $this->getJson('/api/v1/bets/'.$bet->id.'/payout-proof')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Bet not found.');
    }
}
