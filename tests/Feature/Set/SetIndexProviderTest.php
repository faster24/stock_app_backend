<?php

namespace Tests\Feature\Set;

use App\Models\SetSessionResult;
use App\Services\TwoD\SetIndexProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SetIndexProviderTest extends TestCase
{
    use RefreshDatabase;

    private function today(): string
    {
        return Carbon::now((string) config('set.timezone', 'Asia/Bangkok'))->toDateString();
    }

    private function seedResult(string $session, string $twoD, bool $stabilized = true): void
    {
        SetSessionResult::create([
            'result_date' => $this->today(),
            'session' => $session,
            'two_d' => $twoD,
            'digit_one' => $twoD[0],
            'digit_two' => $twoD[1],
            'set_index_value' => '1644.39',
            'set_total_value' => '89284959005',
            'market_status' => 'Closed',
            'stabilized' => $stabilized,
            'attempts' => 1,
            'captured_at' => Carbon::now(),
            'raw_payload' => [],
        ]);
    }

    public function test_fetch_maps_close_sessions_into_a_snapshot(): void
    {
        $this->seedResult('morning_close', '12');
        $this->seedResult('evening_close', '95');
        $this->seedResult('morning_open', '55'); // must be excluded (not a settlement slot)

        $snapshot = $this->app->make(SetIndexProvider::class)->fetch();

        $this->assertCount(2, $snapshot->results);
        $this->assertTrue($snapshot->hasResultFor('12:01'));
        $this->assertTrue($snapshot->hasResultFor('16:30'));

        $byOpenTime = collect($snapshot->results)->keyBy(fn ($r) => $r->openTime);
        $this->assertSame('12', $byOpenTime['12:01:00']->twod);
        $this->assertSame('95', $byOpenTime['16:30:00']->twod);
        $this->assertSame("set-{$this->today()}-evening_close", $byOpenTime['16:30:00']->historyId);
        $this->assertSame($this->today(), $byOpenTime['16:30:00']->stockDate);
    }

    public function test_fetch_ignores_other_dates(): void
    {
        SetSessionResult::create([
            'result_date' => Carbon::now((string) config('set.timezone'))->subDays(3)->toDateString(),
            'session' => 'evening_close',
            'two_d' => '77',
            'stabilized' => true,
            'attempts' => 1,
            'raw_payload' => [],
        ]);

        $snapshot = $this->app->make(SetIndexProvider::class)->fetch();

        $this->assertCount(0, $snapshot->results);
    }
}
