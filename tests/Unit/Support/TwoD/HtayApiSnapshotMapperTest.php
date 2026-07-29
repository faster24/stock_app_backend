<?php

namespace Tests\Unit\Support\TwoD;

use App\Models\TwoDResult;
use App\Services\Set\TradingCalendar;
use App\Services\TwoD\HtayApiFreshnessGuard;
use App\Support\TwoD\HtayApiSnapshotMapper;
use App\Support\TwoD\TwoDPayloadNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HtayApiSnapshotMapperTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A Wednesday, past both slots' publication times (12:31 and 17:00 Bangkok)
     * and past the freshness guard's carry-over grace window, so mapping is
     * exercised without the guard's time gate rejecting rows. Tests that care
     * about the gate itself re-freeze the clock.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-29 17:30', 'Asia/Bangkok'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function mapper(): HtayApiSnapshotMapper
    {
        return new HtayApiSnapshotMapper(new TwoDPayloadNormalizer, new HtayApiFreshnessGuard(new TradingCalendar));
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
        // Must not be null: ordering downstream keys on it, and a null sorted
        // every htayapi row beneath every older row.
        $this->assertSame("{$today} 12:01:00", $morning->stockDateTime);
        $this->assertNull($morning->setIndex);
        $this->assertNull($morning->value);

        $evening = $snapshot->results[1];
        $this->assertSame("htayapi-{$today}-evening", $evening->historyId);
        $this->assertSame('16:30', $evening->openTime);
        $this->assertSame('73', $evening->twod);
        $this->assertSame("{$today} 16:30:00", $evening->stockDateTime);
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

    public function test_stale_carryover_value_excludes_that_slot_within_the_grace_window(): void
    {
        // 12:35 Bangkok — just past the 12:01 MMT slot's 12:31 publication, so
        // an identical value is still treated as upstream carry-over.
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:35', 'Asia/Bangkok'));

        TwoDResult::query()->create([
            'history_id' => 'htayapi-2026-07-27-morning',
            'stock_date' => '2026-07-27',
            'open_time' => '12:01:00',
            'twod' => '85', // identical to today's fixture morning.2d
            'payload' => [],
        ]);

        $snapshot = $this->mapper()->map($this->fullPayload(), 200);

        $openTimes = array_map(fn ($r) => $r->openTime, $snapshot->results);

        $this->assertNotContains('12:01', $openTimes);
        // 16:30 has not published yet at 12:35, so it is withheld too.
        $this->assertNotContains('16:30', $openTimes);
    }

    public function test_a_repeated_value_is_accepted_once_the_grace_window_passes(): void
    {
        TwoDResult::query()->create([
            'history_id' => 'htayapi-2026-07-27-morning',
            'stock_date' => '2026-07-27',
            'open_time' => '12:01:00',
            'twod' => '85',
            'payload' => [],
        ]);

        $snapshot = $this->mapper()->map($this->fullPayload(), 200);

        $openTimes = array_map(fn ($r) => $r->openTime, $snapshot->results);

        $this->assertContains('12:01', $openTimes);
        $this->assertContains('16:30', $openTimes);
    }

    public function test_slots_are_withheld_before_their_publication_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 11:00', 'Asia/Bangkok'));

        $this->assertSame([], $this->mapper()->map($this->fullPayload(), 200)->results);
    }

    public function test_a_payload_with_slot_blocks_is_recognised_even_when_every_row_is_withheld(): void
    {
        // Before publication the guard withholds both slots, but the payload
        // shape is fine — the health check must not read that as unhealthy.
        Carbon::setTestNow(Carbon::parse('2026-07-29 11:00', 'Asia/Bangkok'));

        $snapshot = $this->mapper()->map($this->fullPayload(), 200);

        $this->assertSame([], $snapshot->results);
        $this->assertTrue($snapshot->hasRecognisedPayload());
    }

    public function test_a_payload_without_slot_blocks_is_not_recognised(): void
    {
        $snapshot = $this->mapper()->map(['taiwan' => ['2d' => '96']], 200);

        $this->assertFalse($snapshot->hasRecognisedPayload());
    }
}
