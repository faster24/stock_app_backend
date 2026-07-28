<?php

namespace Tests\Unit\Support\TwoD;

use App\Models\TwoDResult;
use App\Services\TwoD\HtayApiFreshnessGuard;
use App\Support\TwoD\HtayApiSnapshotMapper;
use App\Support\TwoD\TwoDPayloadNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HtayApiSnapshotMapperTest extends TestCase
{
    use RefreshDatabase;

    private function mapper(): HtayApiSnapshotMapper
    {
        return new HtayApiSnapshotMapper(new TwoDPayloadNormalizer, new HtayApiFreshnessGuard);
    }

    private function fullPayload(): array
    {
        return [
            'author' => 'HTAY API',
            'website' => 'https://htayapi.com',
            'country' => 'Thailand',
            'copyright' => 'Legal action will be taken if any unauthorized use of our API is found.',
            'data' => '0',
            'live' => ['set' => '??', 'val' => '??', 'live' => '73'],
            'date' => '2026-07-29 00:43:55 +0630',
            'morning' => ['modern' => '39', 'internet' => '07', '2d' => '85', 'key' => '485'],
            'evening' => ['modern' => '69', 'internet' => '06', '2d' => '73', 'key' => '473'],
            'taiwan' => ['2d' => '96'],
        ];
    }

    public function test_maps_morning_and_evening_using_only_the_2d_field(): void
    {
        Carbon::setTestNow('2026-07-29 13:00:00');

        $snapshot = $this->mapper()->map($this->fullPayload(), 200);

        $this->assertSame(200, $snapshot->upstreamStatus);
        $this->assertCount(2, $snapshot->results);
        $this->assertNull($snapshot->live);
        $this->assertSame($this->fullPayload(), $snapshot->raw);

        $today = Carbon::now('Asia/Bangkok')->toDateString();

        $morning = $snapshot->results[0];
        $this->assertSame("htayapi-{$today}-morning", $morning->historyId);
        $this->assertSame('12:01', $morning->openTime);
        $this->assertSame('85', $morning->twod);
        $this->assertSame($today, $morning->stockDate);
        $this->assertNull($morning->setIndex);
        $this->assertNull($morning->value);

        $evening = $snapshot->results[1];
        $this->assertSame("htayapi-{$today}-evening", $evening->historyId);
        $this->assertSame('16:30', $evening->openTime);
        $this->assertSame('73', $evening->twod);

        Carbon::setTestNow();
    }

    public function test_never_surfaces_modern_internet_key_or_taiwan_values(): void
    {
        $snapshot = $this->mapper()->map($this->fullPayload(), 200);

        $twodValues = array_map(fn ($r) => $r->twod, $snapshot->results);

        $this->assertNotContains('39', $twodValues); // morning.modern
        $this->assertNotContains('07', $twodValues); // morning.internet
        $this->assertNotContains('485', $twodValues); // morning.key
        $this->assertNotContains('69', $twodValues); // evening.modern
        $this->assertNotContains('06', $twodValues); // evening.internet
        $this->assertNotContains('473', $twodValues); // evening.key
        $this->assertNotContains('96', $twodValues); // taiwan.2d
    }

    public function test_missing_morning_key_only_produces_an_evening_row(): void
    {
        $payload = $this->fullPayload();
        unset($payload['morning']);

        $snapshot = $this->mapper()->map($payload, 200);

        $this->assertCount(1, $snapshot->results);
        $this->assertSame('16:30', $snapshot->results[0]->openTime);
    }

    public function test_missing_evening_key_only_produces_a_morning_row(): void
    {
        $payload = $this->fullPayload();
        unset($payload['evening']);

        $snapshot = $this->mapper()->map($payload, 200);

        $this->assertCount(1, $snapshot->results);
        $this->assertSame('12:01', $snapshot->results[0]->openTime);
    }

    public function test_empty_or_placeholder_2d_value_excludes_the_row(): void
    {
        $payload = $this->fullPayload();
        $payload['morning']['2d'] = '--';
        $payload['evening']['2d'] = '';

        $snapshot = $this->mapper()->map($payload, 200);

        $this->assertSame([], $snapshot->results);
    }

    public function test_stale_carryover_value_excludes_that_slot(): void
    {
        Carbon::setTestNow('2026-07-29 13:00:00');

        TwoDResult::query()->create([
            'history_id' => 'htayapi-2026-07-28-morning',
            'stock_date' => '2026-07-28',
            'open_time' => '12:01:00',
            'twod' => '85', // identical to today's fixture morning.2d
            'payload' => [],
        ]);

        $snapshot = $this->mapper()->map($this->fullPayload(), 200);

        $openTimes = array_map(fn ($r) => $r->openTime, $snapshot->results);

        $this->assertNotContains('12:01', $openTimes);
        $this->assertContains('16:30', $openTimes);

        Carbon::setTestNow();
    }
}
