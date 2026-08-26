<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Guard;
use Tests\TestCase;

/**
 * Covers the pentest finding of 27/5/2026: /register was unauthenticated and
 * unthrottled, so accounts could be minted without limit.
 *
 * CACHE_STORE is `array` under phpunit and the app is rebuilt per test method,
 * so limiter counters start clean without any explicit flushing.
 */
class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app('Spatie\\Permission\\PermissionRegistrar')->forgetCachedPermissions();
        call_user_func(
            ['Spatie\\Permission\\Models\\Role', 'findOrCreate'],
            'user',
            Guard::getDefaultName(User::class)
        );
    }

    private function registrationPayload(int $n): array
    {
        return [
            'username' => "player{$n}",
            'email' => "player{$n}@example.com",
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'currency' => 'MMK',
            'pin' => '123456',
            'pin_confirmation' => '123456',
        ];
    }

    public function test_registration_is_throttled_after_five_attempts_per_minute(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/register', $this->registrationPayload($attempt))
                ->assertStatus(201);
        }

        $this->postJson('/api/v1/register', $this->registrationPayload(6))
            ->assertStatus(429)
            ->assertJsonPath('data.code', 'RATE_LIMITED');

        // The point of the fix: the sixth account is never created.
        $this->assertDatabaseCount('users', 5);
    }

    public function test_throttled_response_uses_the_standard_envelope(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/register', $this->registrationPayload($attempt));
        }

        $response = $this->postJson('/api/v1/register', $this->registrationPayload(6))
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'data' => ['code', 'retry_after'], 'errors' => ['throttle']]);

        $this->assertIsInt($response->json('data.retry_after'));
        $this->assertGreaterThan(0, $response->json('data.retry_after'));
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    /**
     * Invalid submissions burn budget too -- otherwise the limit is trivially
     * sidestepped by sending malformed payloads until the window rolls over.
     */
    public function test_failed_registrations_still_count_against_the_limit(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/register', ['username' => 'nope'])
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/register', $this->registrationPayload(1))
            ->assertStatus(429);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_is_throttled_per_ip_and_email_pair(): void
    {
        User::factory()->normalUser()->create(['email' => 'victim@example.com']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/login', [
                'email' => 'victim@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/login', [
            'email' => 'victim@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429)->assertJsonPath('data.code', 'RATE_LIMITED');
    }

    /**
     * The tight login limit is keyed on ip|email precisely so that an attacker
     * hammering one account cannot lock its owner out of a different one.
     */
    public function test_a_different_email_keeps_its_own_login_budget(): void
    {
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->postJson('/api/v1/login', [
                'email' => 'victim@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $this->postJson('/api/v1/login', [
            'email' => 'someone-else@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_the_named_limiters_are_registered(): void
    {
        // `throttle:api` against an undefined limiter is a hard error, not a
        // no-op, so a missing definition would break every route at once.
        foreach (['api', 'register', 'login'] as $limiter) {
            $this->assertNotNull(RateLimiter::limiter($limiter), "Limiter [{$limiter}] is not defined.");
        }
    }
}
