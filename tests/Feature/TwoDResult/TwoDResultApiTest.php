<?php

namespace Tests\Feature\TwoDResult;

use App\Models\TwoDResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoDResultApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_list_two_d_results(): void
    {
        $older = TwoDResult::query()->create([
            'history_id' => 'history-old',
            'stock_date' => '2026-03-20',
            'stock_datetime' => '2026-03-20 12:01:00',
            'open_time' => '12:01:00',
            'twod' => '11',
            'set_index' => '1000.00',
            'value' => '1000.00',
            'payload' => ['history_id' => 'history-old'],
        ]);

        $latest = TwoDResult::query()->create([
            'history_id' => 'history-latest',
            'stock_date' => '2026-03-21',
            'stock_datetime' => '2026-03-21 16:30:00',
            'open_time' => '16:30:00',
            'twod' => '22',
            'set_index' => '1001.00',
            'value' => '1001.00',
            'payload' => ['history_id' => 'history-latest'],
        ]);

        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/two-d-results')
            ->assertOk()
            ->assertJsonPath('message', '2D results retrieved successfully.')
            ->assertJsonPath('data.two_d_results.0.id', $latest->id)
            ->assertJsonPath('data.two_d_results.1.id', $older->id)
            ->assertJsonPath('errors', null);
    }

    public function test_list_supports_filters_and_pagination(): void
    {
        TwoDResult::query()->create([
            'history_id' => 'history-a',
            'stock_date' => '2026-03-21',
            'stock_datetime' => '2026-03-21 12:01:00',
            'open_time' => '12:01:00',
            'twod' => '33',
            'set_index' => '1002.00',
            'value' => '1002.00',
            'payload' => ['history_id' => 'history-a'],
        ]);

        $target = TwoDResult::query()->create([
            'history_id' => 'history-b',
            'stock_date' => '2026-03-21',
            'stock_datetime' => '2026-03-21 16:30:00',
            'open_time' => '16:30:00',
            'twod' => '44',
            'set_index' => '1003.00',
            'value' => '1003.00',
            'payload' => ['history_id' => 'history-b'],
        ]);

        TwoDResult::query()->create([
            'history_id' => 'history-c',
            'stock_date' => '2026-03-22',
            'stock_datetime' => '2026-03-22 12:01:00',
            'open_time' => '12:01:00',
            'twod' => '55',
            'set_index' => '1004.00',
            'value' => '1004.00',
            'payload' => ['history_id' => 'history-c'],
        ]);

        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/two-d-results?stock_date=2026-03-21&open_time=16:30:00&history_id=history-b&page=1&page_size=1')
            ->assertOk()
            ->assertJsonPath('message', '2D results retrieved successfully.')
            ->assertJsonCount(1, 'data.two_d_results')
            ->assertJsonPath('data.two_d_results.0.id', $target->id)
            ->assertJsonPath('errors', null);
    }

    public function test_authenticated_user_can_get_latest_two_d_result_and_null_when_empty(): void
    {
        $user = User::factory()->normalUser()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/two-d-results/latest')
            ->assertOk()
            ->assertJsonPath('message', 'Latest 2D result retrieved successfully.')
            ->assertJsonPath('data.two_d_result', null)
            ->assertJsonPath('errors', null);

        TwoDResult::query()->create([
            'history_id' => 'history-1',
            'stock_date' => '2026-03-21',
            'stock_datetime' => '2026-03-21 12:01:00',
            'open_time' => '12:01:00',
            'twod' => '66',
            'set_index' => '1005.00',
            'value' => '1005.00',
            'payload' => ['history_id' => 'history-1'],
        ]);

        $latest = TwoDResult::query()->create([
            'history_id' => 'history-2',
            'stock_date' => '2026-03-22',
            'stock_datetime' => '2026-03-22 16:30:00',
            'open_time' => '16:30:00',
            'twod' => '77',
            'set_index' => '1006.00',
            'value' => '1006.00',
            'payload' => ['history_id' => 'history-2'],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/two-d-results/latest')
            ->assertOk()
            ->assertJsonPath('message', 'Latest 2D result retrieved successfully.')
            ->assertJsonPath('data.two_d_result.id', $latest->id)
            ->assertJsonPath('errors', null);
    }

    public function test_guest_cannot_read_two_d_results(): void
    {
        $this->getJson('/api/v1/two-d-results')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');

        $this->getJson('/api/v1/two-d-results/latest')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.auth.0', 'Authentication is required.');
    }
}
