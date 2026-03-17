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
                'round_id' => 1,
                'bet_type' => 'NOT_A_REAL_ENUM',
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
                'round_id' => 1,
                'bet_type' => 'STRAIGHT',
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

    public function test_update_rejects_round_id_as_immutable_field_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/test-support/bets/validation/1', [
                'round_id' => 999,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['round_id'],
            ]);
    }
}
