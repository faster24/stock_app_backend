<?php

namespace Tests\Feature\Betting;

use App\Http\Requests\Bet\StoreBetRequest;
use App\Http\Requests\Bet\UpdateBetRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BetApiValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/api/v1/test-support/bets/validation', function (StoreBetRequest $request) {
                return response()->json([
                    'message' => 'Validated.',
                    'data' => $request->validated(),
                    'errors' => null,
                ], 201);
            });

            Route::put('/api/v1/test-support/bets/validation/{bet}', function (UpdateBetRequest $request) {
                return response()->json([
                    'message' => 'Validated.',
                    'data' => $request->validated(),
                    'errors' => null,
                ]);
            });
        });
    }

    public function test_store_rejects_invalid_enum_amount_and_bet_numbers_payload_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/test-support/bets/validation', [
                'bet_type' => 'NOT_A_REAL_ENUM',
                'target_opentime' => '11:00:00',
                'amount' => 0,
                'bet_numbers' => 'not-an-array',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bet_type', 'amount', 'bet_numbers'],
            ]);
    }

    public function test_store_rejects_duplicate_bet_numbers_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/test-support/bets/validation', [
                'bet_type' => '2D',
                'target_opentime' => '11:00:00',
                'amount' => 1000,
                'bet_numbers' => [12, 12],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bet_numbers.1'],
            ]);
    }

    public function test_store_rejects_2d_numbers_outside_10_to_99_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/test-support/bets/validation', [
                'bet_type' => '2D',
                'target_opentime' => '11:00:00',
                'amount' => 1000,
                'bet_numbers' => [9],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bet_numbers.0'],
            ]);
    }

    public function test_store_rejects_3d_numbers_outside_100_to_999_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/test-support/bets/validation', [
                'bet_type' => '3D',
                'target_opentime' => '11:00:00',
                'amount' => 1000,
                'bet_numbers' => [99],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bet_numbers.0'],
            ]);
    }

    public function test_update_rejects_invalid_amount_and_duplicate_bet_numbers_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/test-support/bets/validation/00000000-0000-0000-0000-000000000001', [
                'amount' => 0,
                'bet_numbers' => [99, 99],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['amount', 'bet_numbers.1'],
            ]);
    }

    public function test_store_rejects_invalid_target_opentime_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/test-support/bets/validation', [
                'bet_type' => '2D',
                'target_opentime' => '10:30:00',
                'amount' => 1000,
                'bet_numbers' => [12],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['target_opentime'],
            ]);
    }

    public function test_store_rejects_internal_status_fields_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/test-support/bets/validation', [
                'bet_type' => '2D',
                'target_opentime' => '11:00:00',
                'amount' => 1000,
                'bet_numbers' => [12],
                'status' => 'ACCEPTED',
                'bet_result_status' => 'WON',
                'payout_status' => 'PAID_OUT',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['status', 'bet_result_status', 'payout_status'],
            ]);
    }

    public function test_update_rejects_internal_status_fields_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/test-support/bets/validation/00000000-0000-0000-0000-000000000001', [
                'status' => 'ACCEPTED',
                'bet_result_status' => 'WON',
                'payout_status' => 'PAID_OUT',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['status', 'bet_result_status', 'payout_status'],
            ]);
    }
}
