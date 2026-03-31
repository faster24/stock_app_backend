<?php

namespace Tests\Feature\Betting;

use App\Enums\BetType;
use App\Enums\BankName;
use App\Enums\Currency;
use App\Enums\OddSettingUserType;
use App\Models\OddSetting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BetPaySlipDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_download_pay_slip(): void
    {
        Storage::fake('bet_slips');
        config(['media-library.disk_name' => 'bet_slips']);
        $this->seedDefaultTwoDOdd();

        $owner = User::factory()->normalUser()->create();
        $this->createBankInfo($owner);
        $token = $owner->createToken('auth_token')->plainTextToken;

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/bets', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => BetType::TWO_D->value,
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'transaction_id_last_two_digits' => '45',
                'bet_numbers' => [
                    ['number' => 11, 'amount' => 1500],
                    ['number' => 22, 'amount' => 1500],
                ],
            ])
            ->assertStatus(201);

        $betId = (string) $createResponse->json('data.bet.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/bets/'.$betId.'/pay-slip')
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_non_owner_gets_404_when_downloading_another_users_pay_slip(): void
    {
        Storage::fake('bet_slips');
        config(['media-library.disk_name' => 'bet_slips']);
        $this->seedDefaultTwoDOdd();

        $owner = User::factory()->normalUser()->create();
        $this->createBankInfo($owner);
        $ownerToken = $owner->createToken('auth_token')->plainTextToken;

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/bets', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => BetType::TWO_D->value,
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'transaction_id_last_two_digits' => '45',
                'bet_numbers' => [
                    ['number' => 11, 'amount' => 1500],
                    ['number' => 22, 'amount' => 1500],
                ],
            ])
            ->assertStatus(201);

        $betId = (string) $createResponse->json('data.bet.id');

        $nonOwner = User::factory()->normalUser()->create();
        Sanctum::actingAs($nonOwner);

        $this->getJson('/api/v1/bets/'.$betId.'/pay-slip')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Bet not found.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.bet.0', 'The selected bet is invalid.');
    }

    public function test_admin_can_download_pay_slip(): void
    {
        Storage::fake('bet_slips');
        config(['media-library.disk_name' => 'bet_slips']);
        $this->seedDefaultTwoDOdd();

        $owner = User::factory()->normalUser()->create();
        $this->createBankInfo($owner);
        $ownerToken = $owner->createToken('auth_token')->plainTextToken;

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/bets', [
                'pay_slip_image' => UploadedFile::fake()->image('pay-slip.jpg'),
                'bet_type' => BetType::TWO_D->value,
                'currency' => Currency::MMK->value,
                'target_opentime' => '11:00:00',
                'transaction_id_last_two_digits' => '45',
                'bet_numbers' => [
                    ['number' => 11, 'amount' => 1500],
                    ['number' => 22, 'amount' => 1500],
                ],
            ])
            ->assertStatus(201);

        $betId = (string) $createResponse->json('data.bet.id');

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->get('/api/v1/bets/'.$betId.'/pay-slip')
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    private function seedDefaultTwoDOdd(): void
    {
        OddSetting::query()->updateOrCreate([
            'bet_type' => BetType::TWO_D,
            'currency' => Currency::MMK,
            'user_type' => OddSettingUserType::USER,
        ], [
            'odd' => '80.00',
            'is_active' => true,
        ]);
    }

    private function createBankInfo(User $user): void
    {
        Wallet::query()->create([
            'user_id' => $user->id,
            'bank_name' => BankName::KBZ->value,
            'account_name' => 'Main User',
            'account_number' => '111222333',
        ]);
    }
}
