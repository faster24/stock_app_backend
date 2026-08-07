<?php

namespace Tests\Feature\BetPause;

use App\Models\BetPause;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BetPauseApiTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return User::factory()->admin()->create()->createToken('auth_token')->plainTextToken;
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'bet_type' => '2D',
            'is_enabled' => true,
            'pause_from' => Carbon::now()->subMinute()->toIso8601String(),
            'message' => 'Paused before the draw.',
        ], $overrides);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->putJson('/api/v1/admin/bet-pauses', $this->basePayload())->assertStatus(401);
    }

    public function test_non_admin_cannot_update_bet_pauses(): void
    {
        $token = User::factory()->normalUser()->create()->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/bet-pauses', $this->basePayload())
            ->assertStatus(403);
    }

    public function test_authenticated_user_can_read_bet_pauses(): void
    {
        $token = User::factory()->normalUser()->create()->createToken('auth_token')->plainTextToken;

        BetPause::query()->create([
            'bet_type' => '2D',
            'is_enabled' => true,
            'pause_from' => Carbon::now()->subMinute(),
            'message' => 'Paused.',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/bet-pauses')
            ->assertStatus(200)
            ->assertJsonPath('data.bet_pauses.0.bet_type', '2D')
            ->assertJsonPath('data.bet_pauses.0.is_enabled', true)
            ->assertJsonPath('data.bet_pauses.0.status', 'paused');
    }

    public function test_admin_can_activate_a_pause_with_past_time(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->putJson('/api/v1/admin/bet-pauses', $this->basePayload())
            ->assertStatus(200)
            ->assertJsonPath('message', 'Bet pause updated successfully.')
            ->assertJsonPath('data.bet_pause.is_enabled', true)
            ->assertJsonPath('data.bet_pause.status', 'paused')
            ->assertJsonPath('data.bet_pause.message', 'Paused before the draw.');

        $this->assertDatabaseHas('bet_pauses', [
            'bet_type' => '2D',
            'is_enabled' => true,
        ]);
    }

    public function test_pause_time_with_yangon_offset_is_converted_not_taken_verbatim(): void
    {
        // "Pause now" from the dashboard arrives as Myanmar wall clock, e.g. 17:12:00+06:30.
        // The offset must be honoured: this instant is in the past, so status is paused —
        // not stored as 17:12 UTC (6.5h in the future) reporting scheduled.
        $yangonNow = Carbon::now('Asia/Yangon')->subMinute();

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->putJson('/api/v1/admin/bet-pauses', $this->basePayload([
                'pause_from' => $yangonNow->toIso8601String(),
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.bet_pause.status', 'paused')
            ->assertJsonPath('data.bet_pause.pause_from', $yangonNow->clone()->utc()->toIso8601String());
    }

    public function test_future_pause_time_reports_scheduled_status(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->putJson('/api/v1/admin/bet-pauses', $this->basePayload([
                'pause_from' => Carbon::now()->addHour()->toIso8601String(),
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.bet_pause.status', 'scheduled');
    }

    public function test_resume_clears_pause_fields(): void
    {
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/bet-pauses', $this->basePayload())
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/bet-pauses', [
                'bet_type' => '2D',
                'is_enabled' => false,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.bet_pause.is_enabled', false)
            ->assertJsonPath('data.bet_pause.status', 'inactive')
            ->assertJsonPath('data.bet_pause.pause_from', null)
            ->assertJsonPath('data.bet_pause.message', null);

        $this->assertDatabaseHas('bet_pauses', [
            'bet_type' => '2D',
            'is_enabled' => false,
            'pause_from' => null,
            'message' => null,
        ]);
    }

    public function test_repeated_updates_upsert_a_single_row(): void
    {
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/bet-pauses', $this->basePayload())
            ->assertStatus(200);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/bet-pauses', $this->basePayload(['message' => 'Updated message.']))
            ->assertStatus(200);

        $this->assertDatabaseCount('bet_pauses', 1);
        $this->assertDatabaseHas('bet_pauses', ['message' => 'Updated message.']);
    }

    public function test_pause_from_is_required_when_enabling(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->putJson('/api/v1/admin/bet-pauses', $this->basePayload(['pause_from' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pause_from']);
    }

    public function test_three_d_can_be_paused(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->putJson('/api/v1/admin/bet-pauses', $this->basePayload([
                'bet_type' => '3D',
                'message' => '3D betting closed for the draw.',
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.bet_pause.bet_type', '3D')
            ->assertJsonPath('data.bet_pause.status', 'paused');

        $this->assertDatabaseHas('bet_pauses', [
            'bet_type' => '3D',
            'is_enabled' => true,
        ]);
    }

    public function test_two_d_and_three_d_pauses_are_independent_rows(): void
    {
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/bet-pauses', $this->basePayload(['bet_type' => '2D']))
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/admin/bet-pauses', $this->basePayload(['bet_type' => '3D']))
            ->assertStatus(200);

        // The unique key is on bet_type alone, so pausing one must not overwrite the other.
        $this->assertDatabaseCount('bet_pauses', 2);
    }

    public function test_invalid_bet_type_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->putJson('/api/v1/admin/bet-pauses', $this->basePayload(['bet_type' => '4D']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bet_type']);
    }

    public function test_unexpected_fields_are_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->putJson('/api/v1/admin/bet-pauses', $this->basePayload(['currency' => 'MMK']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }
}
