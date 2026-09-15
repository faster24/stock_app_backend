<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthFailureCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_registration_email_returns_422_with_email_error(): void
    {
        User::factory()->create([
            'email' => 'duplicate@example.com',
        ]);

        $response = $this->postJson('/api/v1/register', [
            'username'              => 'duplicate-user',
            'email'                 => 'duplicate@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'currency'              => 'MMK',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['email'],
            ]);
    }

    public function test_duplicate_registration_phone_returns_422_with_phone_error(): void
    {
        User::factory()->create([
            'phone' => '0912345678',
        ]);

        $response = $this->postJson('/api/v1/register', [
            'username'              => 'duplicate-phone-user',
            'email'                 => 'duplicate-phone@example.com',
            'phone'                 => '+95912345678',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'currency'              => 'MMK',
            'pin'                   => '123456',
            'pin_confirmation'      => '123456',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['phone'],
            ]);
    }

    public function test_register_malformed_phone_returns_422(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'username'              => 'badphone',
            'email'                 => 'badphone@example.com',
            'phone'                 => '09-123 456',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'currency'              => 'MMK',
            'pin'                   => '123456',
            'pin_confirmation'      => '123456',
        ]);

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['phone']]);
    }

    public function test_duplicate_registration_phone_in_e164_form_returns_422(): void
    {
        User::factory()->create([
            'phone' => '+66812345678',
        ]);

        $response = $this->postJson('/api/v1/register', $this->phonePayload('+66812345678'));

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['phone']]);
    }

    public function test_register_accepts_thai_mobile_phone(): void
    {
        $response = $this->postJson('/api/v1/register', $this->phonePayload('+66 81-234-5678'));

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.user.phone', '+66812345678');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPhoneProvider(): array
    {
        return [
            'thai landline' => ['+6621234567'],
            'thai too short' => ['+6681234567'],
            'thai too long' => ['+668123456789'],
            'myanmar too short' => ['+959123456'],
            'myanmar too long' => ['+9591234567890'],
            'myanmar not a mobile' => ['+95112345678'],
            'unsupported country' => ['+14155550100'],
            'local form without prefix' => ['0912345678'],
        ];
    }

    #[DataProvider('invalidPhoneProvider')]
    public function test_register_rejects_invalid_phone(string $phone): void
    {
        $response = $this->postJson('/api/v1/register', $this->phonePayload($phone));

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['phone']]);
    }

    /**
     * @return array<string, string>
     */
    private function phonePayload(string $phone): array
    {
        return [
            'username'              => 'phoneuser',
            'email'                 => 'phoneuser@example.com',
            'phone'                 => $phone,
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'currency'              => 'MMK',
            'pin'                   => '123456',
            'pin_confirmation'      => '123456',
        ];
    }

    public function test_register_missing_phone_returns_422(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'username'              => 'nophone',
            'email'                 => 'nophone@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'currency'              => 'MMK',
            'pin'                   => '123456',
            'pin_confirmation'      => '123456',
        ]);

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['phone']]);
    }

    public function test_register_missing_currency_returns_422(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'username'              => 'nocurrency',
            'email'                 => 'nocurrency@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['currency']]);
    }

    public function test_register_invalid_currency_returns_422(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'username'              => 'badcurrency',
            'email'                 => 'badcurrency@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'currency'              => 'USD',
        ]);

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['currency']]);
    }

    public function test_invalid_login_password_returns_401_with_credentials_error_envelope(): void
    {
        User::factory()->create([
            'email' => 'wrong-pass@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'wrong-pass@example.com',
            'password' => 'incorrect-password',
        ]);

        $response
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.credentials.0', 'The provided credentials are incorrect.')
            ->assertJsonStructure([
                'message',
                'data',
                'errors' => ['credentials'],
            ]);
    }

    public function test_me_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_logout_without_token_returns_401(): void
    {
        $response = $this->postJson('/api/v1/logout');

        $response
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
