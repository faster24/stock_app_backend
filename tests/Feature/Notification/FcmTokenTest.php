<?php

namespace Tests\Feature\Notification;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FcmTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->normalUser()->create();
        $this->token = str_repeat('a', 163);
    }

    private function actingAsUser(): static
    {
        $bearer = $this->user->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$bearer);
    }

    public function test_guest_cannot_register_token(): void
    {
        $this->postJson('/api/v1/fcm/token', [
            'token' => $this->token,
            'device_type' => 'web',
        ])->assertStatus(401);
    }

    public function test_can_register_fcm_token(): void
    {
        $response = $this->actingAsUser()->postJson('/api/v1/fcm/token', [
            'token' => $this->token,
            'device_type' => 'web',
            'device_name' => 'Chrome',
        ]);

        $response->assertStatus(201)->assertJsonPath('message', 'FCM token registered successfully');

        $this->assertDatabaseHas('fcm_tokens', [
            'user_id' => $this->user->id,
            'token' => $this->token,
            'device_type' => 'web',
            'is_active' => 1,
        ]);
    }

    public function test_registering_same_token_twice_is_idempotent(): void
    {
        $payload = ['token' => $this->token, 'device_type' => 'android'];

        $this->actingAsUser()->postJson('/api/v1/fcm/token', $payload)->assertStatus(201);
        $this->actingAsUser()->postJson('/api/v1/fcm/token', $payload)->assertStatus(201);

        $this->assertDatabaseCount('fcm_tokens', 1);
    }

    public function test_token_must_be_at_least_100_chars(): void
    {
        $this->actingAsUser()->postJson('/api/v1/fcm/token', [
            'token' => str_repeat('x', 99),
            'device_type' => 'web',
        ])->assertStatus(422)->assertJsonValidationErrors(['token']);
    }

    public function test_device_type_must_be_valid(): void
    {
        $this->actingAsUser()->postJson('/api/v1/fcm/token', [
            'token' => $this->token,
            'device_type' => 'desktop',
        ])->assertStatus(422)->assertJsonValidationErrors(['device_type']);
    }

    public function test_can_list_tokens(): void
    {
        FcmToken::factory()->create(['user_id' => $this->user->id]);
        FcmToken::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAsUser()->getJson('/api/v1/fcm/tokens');

        $response->assertOk()->assertJsonPath('count', 2);
    }

    public function test_listed_tokens_hide_raw_token_value(): void
    {
        FcmToken::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAsUser()->getJson('/api/v1/fcm/tokens');

        $response->assertOk();
        collect($response->json('data'))->each(function ($item): void {
            $this->assertArrayNotHasKey('token', $item);
        });
    }

    public function test_can_remove_fcm_token(): void
    {
        FcmToken::factory()->create([
            'user_id' => $this->user->id,
            'token' => $this->token,
        ]);

        $this->actingAsUser()->deleteJson('/api/v1/fcm/token', [
            'token' => $this->token,
        ])->assertOk();

        $this->assertDatabaseMissing('fcm_tokens', ['token' => $this->token]);
    }

    public function test_remove_nonexistent_token_returns_404(): void
    {
        $this->actingAsUser()->deleteJson('/api/v1/fcm/token', [
            'token' => $this->token,
        ])->assertStatus(404);
    }

    public function test_cannot_remove_another_users_token(): void
    {
        $other = User::factory()->normalUser()->create();
        FcmToken::factory()->create(['user_id' => $other->id, 'token' => $this->token]);

        $this->actingAsUser()->deleteJson('/api/v1/fcm/token', [
            'token' => $this->token,
        ])->assertStatus(404);

        $this->assertDatabaseHas('fcm_tokens', ['token' => $this->token]);
    }

    public function test_logout_all_deactivates_all_tokens(): void
    {
        FcmToken::factory()->count(3)->create(['user_id' => $this->user->id]);

        $this->actingAsUser()->postJson('/api/v1/fcm/logout-all')->assertOk();

        $active = FcmToken::where('user_id', $this->user->id)->where('is_active', true)->count();
        $this->assertSame(0, $active);
    }

    public function test_logout_all_does_not_affect_other_users(): void
    {
        $other = User::factory()->normalUser()->create();
        FcmToken::factory()->create(['user_id' => $other->id]);

        $this->actingAsUser()->postJson('/api/v1/fcm/logout-all')->assertOk();

        $this->assertDatabaseHas('fcm_tokens', ['user_id' => $other->id, 'is_active' => 1]);
    }
}
