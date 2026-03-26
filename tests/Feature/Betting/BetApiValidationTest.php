<?php

namespace Tests\Feature\Betting;

use App\Http\Requests\Bet\StoreBetRequest;
use App\Http\Requests\Bet\UpdateBetRequest;
use App\Enums\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
                    'data' => array_keys($request->validated()),
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

    public function test_store_rejects_invalid_enum_and_bet_numbers_payload_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/test-support/bets/validation', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => 'NOT_A_REAL_ENUM',
                'currency' => 'USD',
                'target_opentime' => '11:00:00',
                'bet_numbers' => 'not-an-array',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bet_type', 'currency', 'bet_numbers'],
            ]);
    }

    public function test_store_rejects_duplicate_bet_numbers_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/test-support/bets/validation', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '2D',
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'bet_numbers' => [
                    ['number' => 12, 'amount' => 1000],
                    ['number' => 12, 'amount' => 1000],
                ],
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

    public function test_store_rejects_2d_numbers_outside_1_to_99_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/test-support/bets/validation', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '2D',
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'bet_numbers' => [['number' => 0, 'amount' => 1000]],
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

    public function test_store_rejects_3d_numbers_outside_1_to_999_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/test-support/bets/validation', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '3D',
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'bet_numbers' => [['number' => 0, 'amount' => 1000]],
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

    public function test_update_rejects_duplicate_bet_numbers_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/test-support/bets/validation/00000000-0000-0000-0000-000000000001', [
                'bet_numbers' => [
                    ['number' => 99, 'amount' => 1000],
                    ['number' => 99, 'amount' => 1200],
                ],
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

    public function test_store_rejects_invalid_target_opentime_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/test-support/bets/validation', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '2D',
                'currency' => Currency::MMK->value,
                'target_opentime' => '10:30:00',
                'bet_numbers' => [['number' => 12, 'amount' => 1000]],
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
            ->post('/api/v1/test-support/bets/validation', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '2D',
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'bet_numbers' => [['number' => 12, 'amount' => 1000]],
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

    public function test_store_requires_pay_slip_image_with_422_envelope(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/test-support/bets/validation', [
                'bet_type' => '2D',
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'bet_numbers' => [['number' => 12, 'amount' => 1000]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['pay_slip_image'],
            ]);
    }

    public function test_store_rejects_object_bet_number_without_amount(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/test-support/bets/validation', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '2D',
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'bet_numbers' => [
                    ['number' => 12],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['bet_numbers.0.amount'],
            ]);
    }

    public function test_store_rejects_legacy_integer_bet_numbers_payload(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/test-support/bets/validation', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '2D',
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'bet_numbers' => [12],
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

    public function test_store_accepts_leading_zero_string_numbers_for_2d_and_3d(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/test-support/bets/validation', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '2D',
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'bet_numbers' => [
                    ['number' => '01', 'amount' => 1000],
                    ['number' => '09', 'amount' => 1000],
                    ['number' => '99', 'amount' => 1000],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('message', 'Validated.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/test-support/bets/validation', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '3D',
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'bet_numbers' => [
                    ['number' => '001', 'amount' => 1000],
                    ['number' => '099', 'amount' => 1000],
                    ['number' => '999', 'amount' => 1000],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('message', 'Validated.');
    }

    public function test_store_rejects_normalized_duplicate_numbers(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/test-support/bets/validation', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => '2D',
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'bet_numbers' => [
                    ['number' => 1, 'amount' => 1000],
                    ['number' => '01', 'amount' => 1000],
                ],
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
}
