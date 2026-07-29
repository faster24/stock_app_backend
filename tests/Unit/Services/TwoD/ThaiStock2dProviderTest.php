<?php

namespace Tests\Unit\Services\TwoD;

use App\Contracts\TwoDLiveProvider;
use App\Exceptions\TwoDProviderException;
use App\Services\TwoD\ThaiStock2dProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Exercises the real thaistock2d provider — the one place the vendor URL and
 * JSON shape are known — against a faked HTTP client. This is where the upstream
 * payload contract is asserted; consumers are tested against the fake provider.
 */
class ThaiStock2dProviderTest extends TestCase
{
    private function provider(): ThaiStock2dProvider
    {
        $resolved = $this->app->make(TwoDLiveProvider::class);
        $this->assertInstanceOf(ThaiStock2dProvider::class, $resolved);

        return $resolved;
    }

    private function fullPayload(string $openTime = '16:30', string $twod = '05'): array
    {
        return [
            'server_time' => '2026-05-05 17:00:00',
            'live' => [
                'set' => '1,490.10',
                'value' => '73,115.21',
                'time' => '2026-05-05 16:31:58',
                'twod' => $twod,
                'date' => '2026-05-05',
            ],
            'result' => [
                [
                    'set' => '1,490.19',
                    'value' => '38,194.99',
                    'open_time' => $openTime.':00',
                    'twod' => $twod,
                    'stock_date' => '2026-05-05',
                    'stock_datetime' => '2026-05-05 '.$openTime.':01',
                    'history_id' => '9999999',
                ],
            ],
            'holiday' => ['status' => '2', 'date' => '2026-05-05', 'name' => 'NULL'],
        ];
    }

    public function test_maps_result_rows_and_live_into_a_normalized_snapshot(): void
    {
        Http::fake([
            'api.thaistock2d.com/live' => Http::response($this->fullPayload('16:30', '05'), 200),
        ]);

        $snapshot = $this->provider()->fetch();

        $this->assertSame(200, $snapshot->upstreamStatus);
        $this->assertTrue($snapshot->hasRecognisedPayload());
        $this->assertTrue($snapshot->hasResultFor('16:30'));

        $this->assertCount(1, $snapshot->results);
        $result = $snapshot->results[0];
        $this->assertSame('9999999', $result->historyId);
        $this->assertSame('2026-05-05', $result->stockDate);
        $this->assertSame('16:30:00', $result->openTime);
        $this->assertSame('05', $result->twod);

        $this->assertNotNull($snapshot->live);
        $this->assertSame('05', $snapshot->live->twod);
        $this->assertSame('2026-05-05', $snapshot->live->date);
        $this->assertSame('2026-05-05 16:31:58', $snapshot->live->time);
    }

    public function test_skips_result_rows_without_a_history_id(): void
    {
        $payload = $this->fullPayload();
        $payload['result'][0]['history_id'] = null;

        Http::fake([
            'api.thaistock2d.com/live' => Http::response($payload, 200),
        ]);

        $snapshot = $this->provider()->fetch();

        $this->assertSame([], $snapshot->results);
        $this->assertTrue($snapshot->hasRecognisedPayload());
        $this->assertFalse($snapshot->hasResultFor('16:30'));
    }

    public function test_maps_a_live_only_payload(): void
    {
        Http::fake([
            'api.thaistock2d.com/live' => Http::response([
                'live' => ['twod' => '42', 'time' => '2026-05-05 12:01:30', 'date' => '2026-05-05'],
                'result' => [],
            ], 200),
        ]);

        $snapshot = $this->provider()->fetch();

        $this->assertSame([], $snapshot->results);
        $this->assertNotNull($snapshot->live);
        $this->assertSame('42', $snapshot->live->twod);
    }

    public function test_throws_with_status_on_non_2xx_response(): void
    {
        Http::fake([
            'api.thaistock2d.com/live' => Http::response(['message' => 'boom'], 500),
        ]);

        try {
            $this->provider()->fetch();
            $this->fail('Expected TwoDProviderException.');
        } catch (TwoDProviderException $e) {
            $this->assertSame(500, $e->upstreamStatus());
        }
    }

    public function test_throws_with_null_status_on_transport_exception(): void
    {
        Http::fake(function (): never {
            throw new \RuntimeException('connection refused');
        });

        try {
            $this->provider()->fetch();
            $this->fail('Expected TwoDProviderException.');
        } catch (TwoDProviderException $e) {
            $this->assertNull($e->upstreamStatus());
        }
    }

    public function test_throws_when_payload_is_not_a_json_object(): void
    {
        Http::fake([
            'api.thaistock2d.com/live' => Http::response('not json', 200),
        ]);

        try {
            $this->provider()->fetch();
            $this->fail('Expected TwoDProviderException.');
        } catch (TwoDProviderException $e) {
            $this->assertSame(200, $e->upstreamStatus());
        }
    }
}
