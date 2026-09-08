<?php

namespace Tests\Feature\Auth;

use App\Enums\Currency;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Auth\SecurityPinVerifier;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The security PIN contract at the HTTP boundary — the level the app sees.
 *
 * Betting used to be the endpoint that exercised it; the PIN was dropped from
 * bet placement, so withdrawals are now the only money path that asks for one
 * and these tests drive it from there. The verifier, its message and its
 * throttle counter are unchanged, so what is asserted here is unchanged too.
 */
class SecurityPinTest extends TestCase
{
    use RefreshDatabase;

    public function test_correct_pin_creates_the_withdrawal(): void
    {
        [, $token] = $this->playerWithWallet();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/withdrawals', $this->withdrawalPayload())
            ->assertStatus(201);

        $this->assertDatabaseCount('withdrawals', 1);
    }

    public function test_wrong_pin_is_rejected_and_persists_nothing(): void
    {
        [, $token] = $this->playerWithWallet();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/withdrawals', $this->withdrawalPayload(['security_pin' => '999999']))
            ->assertStatus(422)
            ->assertJsonPath('errors.security_pin.0', 'Invalid security PIN.');

        $this->assertDatabaseCount('withdrawals', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    /**
     * The regression that made a PIN incident unreadable. A mistyped PIN is an
     * outcome; logging it at error put it on the Telegram alert channel and
     * throttled the genuine 500s behind it for five minutes.
     */
    public function test_a_wrong_pin_is_not_logged_as_an_unexpected_error(): void
    {
        [, $token] = $this->playerWithWallet();

        Log::spy();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/withdrawals', $this->withdrawalPayload(['security_pin' => '999999']))
            ->assertStatus(422);

        Log::shouldNotHaveReceived('error');
    }

    /**
     * A PIN written into the column by hand — the only way to help a locked-out
     * player before the endpoints below existed — fails Hash::check forever and
     * is indistinguishable from a typo. It must say so, and it must never be
     * accepted as a credential.
     */
    public function test_an_unhashed_stored_pin_asks_for_a_reset_rather_than_looking_like_a_typo(): void
    {
        [$user, $token] = $this->playerWithWallet();

        $this->writeRawPin($user, '123456');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/withdrawals', $this->withdrawalPayload())
            ->assertStatus(409)
            ->assertJsonPath('errors.domain.0', SecurityPinVerifier::RESET_REQUIRED_MESSAGE);

        $this->assertDatabaseCount('withdrawals', 0);
    }

    public function test_registration_stores_the_pin_hashed(): void
    {
        $this->postJson('/api/v1/register', [
            'username' => 'pintester',
            'email' => 'pintester@example.com',
            'phone' => '0913579024',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'pin' => '135790',
            'pin_confirmation' => '135790',
        ])->assertStatus(201);

        $user = User::query()->where('email', 'pintester@example.com')->firstOrFail();

        $this->assertNotSame('135790', $user->security_pin);
        $this->assertTrue(Hash::isHashed($user->security_pin));
        $this->assertTrue(Hash::check('135790', $user->security_pin));
        $this->assertNotNull($user->security_pin_set_at);
    }

    public function test_a_player_changes_their_own_pin_with_their_password(): void
    {
        [$user, $token] = $this->playerWithWallet();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/security-pin', [
                'password' => 'password',
                'pin' => '654321',
                'pin_confirmation' => '654321',
            ])
            ->assertStatus(200);

        $this->assertTrue(Hash::check('654321', $user->fresh()->security_pin));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/withdrawals', $this->withdrawalPayload(['security_pin' => UserFactory::TEST_PIN]))
            ->assertStatus(422)
            ->assertJsonPath('errors.security_pin.0', 'Invalid security PIN.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/withdrawals', $this->withdrawalPayload(['security_pin' => '654321']))
            ->assertStatus(201);
    }

    public function test_changing_the_pin_with_a_wrong_password_is_rejected(): void
    {
        [$user, $token] = $this->playerWithWallet();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/security-pin', [
                'password' => 'not-the-password',
                'pin' => '654321',
                'pin_confirmation' => '654321',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'The provided password is incorrect.');

        $this->assertTrue(Hash::check(UserFactory::TEST_PIN, $user->fresh()->security_pin));
    }

    public function test_changing_the_pin_clears_a_throttle_the_player_is_serving(): void
    {
        [, $token] = $this->playerWithWallet();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/api/v1/withdrawals', $this->withdrawalPayload(['security_pin' => '999999']))
                ->assertStatus(422);
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/withdrawals', $this->withdrawalPayload(['security_pin' => '999999']))
            ->assertStatus(429)
            ->assertJsonPath('data.code', 'SECURITY_PIN_THROTTLED');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/security-pin', [
                'password' => 'password',
                'pin' => '654321',
                'pin_confirmation' => '654321',
            ])
            ->assertStatus(200);

        // Without the clear in setForUser(), a player who resets after locking
        // themselves out would still wait out the minute with a valid PIN.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/withdrawals', $this->withdrawalPayload(['security_pin' => '654321']))
            ->assertStatus(201);
    }

    public function test_an_admin_resets_a_locked_out_players_pin(): void
    {
        [$user, $token] = $this->playerWithWallet();
        $this->writeRawPin($user, '123456');

        $adminToken = User::factory()->admin()->create()->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/v1/admin/users/'.$user->id.'/reset-security-pin', ['pin' => '246810'])
            ->assertStatus(200)
            ->assertJsonPath('data', null);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/withdrawals', $this->withdrawalPayload(['security_pin' => '246810']))
            ->assertStatus(201);
    }

    public function test_a_player_cannot_reset_another_players_pin(): void
    {
        [$user, $token] = $this->playerWithWallet();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/users/'.$user->id.'/reset-security-pin', ['pin' => '246810'])
            ->assertStatus(403);

        $this->assertTrue(Hash::check(UserFactory::TEST_PIN, $user->fresh()->security_pin));
    }

    /**
     * Straight past the model's `hashed` cast, because that is what editing the
     * column by hand does — and the reason an unhashed PIN can exist at all.
     */
    private function writeRawPin(User $user, string $pin): void
    {
        DB::table('users')->where('id', $user->id)->update(['security_pin' => $pin]);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function playerWithWallet(): array
    {
        $user = User::factory()->normalUser()->create();

        Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100_000,
            'currency' => Currency::MMK,
            'currency_locked_at' => now(),
            'bank_name' => 'KBZ',
            'account_name' => 'Test User',
            'account_number' => '1234567890',
        ]);

        return [$user, $user->createToken('auth_token')->plainTextToken];
    }

    private function withdrawalPayload(array $overrides = []): array
    {
        return array_merge([
            'currency' => 'MMK',
            'amount' => 1_000,
            'security_pin' => UserFactory::TEST_PIN,
        ], $overrides);
    }
}
