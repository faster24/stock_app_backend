<?php

namespace Tests\Feature\TwoD;

use App\Enums\TwoDSideSlot;
use App\Models\TwoDSideNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoDSideNumberApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedPair(string $resultDate, TwoDSideSlot $slot, string $modern, string $internet): TwoDSideNumber
    {
        return TwoDSideNumber::query()->create([
            'result_date' => $resultDate,
            'slot' => $slot->value,
            'modern' => $modern,
            'internet' => $internet,
            'raw_payload' => ['modern' => $modern, 'internet' => $internet, '2d' => '85', 'key' => '485'],
        ]);
    }

    private function tokenHeader(): array
    {
        $user = User::factory()->normalUser()->create();

        return ['Authorization' => 'Bearer '.$user->createToken('auth_token')->plainTextToken];
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/two-d-side-numbers/last-5-days')->assertUnauthorized();
    }

    public function test_returns_side_numbers_with_display_time(): void
    {
        $this->seedPair('2026-07-22', TwoDSideSlot::MORNING, '39', '07');

        $this->withHeaders($this->tokenHeader())
            ->getJson('/api/v1/two-d-side-numbers/last-5-days')
            ->assertOk()
            ->assertJsonPath('message', 'Last 5 days 2D side numbers retrieved successfully.')
            ->assertJsonPath('data.two_d_side_numbers.0.slot', 'morning')
            ->assertJsonPath('data.two_d_side_numbers.0.modern', '39')
            ->assertJsonPath('data.two_d_side_numbers.0.internet', '07')
            ->assertJsonPath('data.two_d_side_numbers.0.result_date', '2026-07-22')
            ->assertJsonPath('data.two_d_side_numbers.0.display_time', '09:30:00')
            ->assertJsonPath('errors', null);
    }

    public function test_the_evening_slot_reports_its_afternoon_display_time(): void
    {
        $this->seedPair('2026-07-22', TwoDSideSlot::EVENING, '69', '06');

        $this->withHeaders($this->tokenHeader())
            ->getJson('/api/v1/two-d-side-numbers/last-5-days')
            ->assertOk()
            ->assertJsonPath('data.two_d_side_numbers.0.display_time', '14:00:00');
    }

    /**
     * raw_payload carries the slot block's `2d` settlement number. Shipping it
     * inside a row labelled 09:30 would invite a client to render a settlement
     * value as an indicator number — exactly what the separate table prevents.
     */
    public function test_raw_payload_is_never_exposed(): void
    {
        $this->seedPair('2026-07-22', TwoDSideSlot::MORNING, '39', '07');

        $response = $this->withHeaders($this->tokenHeader())
            ->getJson('/api/v1/two-d-side-numbers/last-5-days')
            ->assertOk();

        $response->assertJsonMissingPath('data.two_d_side_numbers.0.raw_payload');
        $this->assertStringNotContainsString('raw_payload', $response->getContent());
        $this->assertStringNotContainsString('485', $response->getContent());
    }

    public function test_returns_only_the_latest_five_dates(): void
    {
        foreach (['2026-07-13', '2026-07-14', '2026-07-15', '2026-07-16', '2026-07-17', '2026-07-20'] as $date) {
            $this->seedPair($date, TwoDSideSlot::MORNING, '39', '07');
        }

        $response = $this->withHeaders($this->tokenHeader())
            ->getJson('/api/v1/two-d-side-numbers/last-5-days')
            ->assertOk();

        $dates = array_column($response->json('data.two_d_side_numbers'), 'result_date');

        $this->assertCount(5, $dates);
        $this->assertNotContains('2026-07-13', $dates);
        $this->assertSame('2026-07-20', $dates[0]);
    }

    public function test_returns_an_empty_list_when_nothing_is_stored(): void
    {
        $this->withHeaders($this->tokenHeader())
            ->getJson('/api/v1/two-d-side-numbers/last-5-days')
            ->assertOk()
            ->assertJsonPath('data.two_d_side_numbers', []);
    }
}
