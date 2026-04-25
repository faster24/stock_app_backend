<?php

namespace Tests\Feature\ThreeDResult;

use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Models\ThreeDResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThreeDResultApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_list_three_d_results_and_get_latest(): void
    {
        $older = ThreeDResult::query()->create([
            'stock_date' => '2026-03-20',
            'threed' => '111',
        ]);

        $latest = ThreeDResult::query()->create([
            'stock_date' => '2026-03-21',
            'threed' => '222',
        ]);

        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/three-d-results')
            ->assertOk()
            ->assertJsonPath('message', '3D results retrieved successfully.')
            ->assertJsonPath('data.three_d_results.0.id', $latest->id)
            ->assertJsonPath('data.three_d_results.1.id', $older->id)
            ->assertJsonPath('errors', null);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/three-d-results/latest')
            ->assertOk()
            ->assertJsonPath('message', 'Latest 3D result retrieved successfully.')
            ->assertJsonPath('data.three_d_result.id', $latest->id)
            ->assertJsonPath('errors', null);
    }

    public function test_guest_cannot_read_three_d_results(): void
    {
        $this->getJson('/api/v1/three-d-results')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');

        $this->getJson('/api/v1/three-d-results/latest')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');
    }

    public function test_admin_can_create_update_delete_three_d_result_and_create_triggers_settlement(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('auth_token')->plainTextToken;
        $user = User::factory()->normalUser()->create();

        $winningBet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::THREE_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::OPEN,
            'stock_date' => '2026-03-24',
        ]);
        $winningBet->betNumbers()->createMany([
            ['number' => 1, 'amount' => 1000],
        ]);

        $losingBet = Bet::factory()->for($user)->create([
            'bet_type' => BetType::THREE_D,
            'status' => BetStatus::ACCEPTED,
            'bet_result_status' => BetResultStatus::OPEN,
            'stock_date' => '2026-03-24',
        ]);
        $losingBet->betNumbers()->createMany([
            ['number' => 456],
        ]);

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/v1/admin/three-d-results', [
                'stock_date' => '2026-03-24',
                'threed' => '001',
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('message', '3D result saved successfully.')
            ->assertJsonPath('data.three_d_result.stock_date', '2026-03-24')
            ->assertJsonPath('data.three_d_result.threed', '001')
            ->assertJsonPath('errors', null);

        $createdResultId = $createResponse->json('data.three_d_result.id');

        $this->assertDatabaseHas('bets', [
            'id' => $winningBet->id,
            'bet_result_status' => BetResultStatus::WON->value,
            'settled_result_history_id' => '3d-result-2026-03-24',
        ]);

        $this->assertDatabaseHas('bets', [
            'id' => $losingBet->id,
            'bet_result_status' => BetResultStatus::LOST->value,
            'settled_result_history_id' => '3d-result-2026-03-24',
        ]);

        $this->assertDatabaseHas('bet_settlement_runs', [
            'history_id' => '3d-result-2026-03-24',
            'bet_type' => BetType::THREE_D->value,
            'three_d_result_id' => $createdResultId,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->putJson('/api/v1/admin/three-d-results/'.$createdResultId, [
                'threed' => '001',
            ])
            ->assertOk()
            ->assertJsonPath('message', '3D result updated successfully.')
            ->assertJsonPath('data.three_d_result.id', $createdResultId)
            ->assertJsonPath('errors', null);

        $this->assertDatabaseCount('bet_settlement_runs', 1);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->deleteJson('/api/v1/admin/three-d-results/'.$createdResultId)
            ->assertOk()
            ->assertJsonPath('message', '3D result deleted successfully.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors', null);

        $this->assertDatabaseMissing('three_d_results', [
            'id' => $createdResultId,
        ]);
    }

    public function test_non_admin_cannot_write_three_d_results(): void
    {
        $result = ThreeDResult::query()->create([
            'stock_date' => '2026-03-25',
            'threed' => '789',
        ]);

        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/three-d-results', [
                'stock_date' => '2026-03-26',
                'threed' => '999',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/three-d-results/'.$result->id, [
                'threed' => '999',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/admin/three-d-results/'.$result->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.')
            ->assertJsonPath('errors.authorization.0', 'You do not have permission to access this resource.');
    }
}
