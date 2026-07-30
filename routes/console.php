<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 2D settlement slots. Slot labels ("12:01"/"16:30") are Myanmar times and are
// passed through verbatim as open_time for bet lookup, but the schedule runs in
// Asia/Bangkok — 30 minutes ahead of Asia/Yangon. Every dailyAt() below is
// therefore the Myanmar slot + 30 minutes; firing at the raw slot label starts
// the fetch before the number is published.
//
// Retry cadence depends on the active provider: htayapi's
// test key is capped at 100 requests/day, so it gets a sparse cadence (4
// attempts, 5 minutes apart, ~8 scheduled calls/day total) instead of
// thaistock2d's unlimited-quota polling. Branching here — not inside
// FetchAndSettleTwoDCommand — keeps the command provider-agnostic and means
// the same TWOD_DRIVER flip that selects the provider also selects safe
// scheduling, with no separate env variable to remember.
if (config('services.twod.driver') === 'htayapi') {
    // 12:01 MMT slot — triggers at 12:31 Bangkok
    Schedule::command('twod:fetch-and-settle 12:01 --timeout-minutes=20 --retry-interval=300 --max-attempts=4')
        ->timezone('Asia/Bangkok')
        ->withoutOverlapping(30)
        ->dailyAt('12:31')
        ->appendOutputTo(storage_path('logs/scheduler.log'));

    // 16:30 MMT slot — triggers at 17:00 Bangkok
    Schedule::command('twod:fetch-and-settle 16:30 --timeout-minutes=20 --retry-interval=300 --max-attempts=4')
        ->timezone('Asia/Bangkok')
        ->withoutOverlapping(30)
        ->dailyAt('17:00')
        ->appendOutputTo(storage_path('logs/scheduler.log'));

    // ----------------------------------------------------------------------
    // Side numbers (modern/internet). Display only — these never settle bets.
    // htayapi-only: no other provider carries these fields.
    //
    // Same MMT+30 rule as above, plus 2 minutes so attempt 1 lands just PAST
    // publication rather than exactly on it. Attempts fall at +0/+5/+10 min, so
    // the last one clears the freshness guard's 10-minute carry-over grace and
    // a legitimately repeated pair is still picked up.
    //
    // Budget: 3 attempts each, worst case 6/day, on top of the settlement
    // slots' 8 — 14 of the 25/day ceiling, leaving 11 for the health check and
    // manual re-fetches. In practice both loops exit on the first success.
    // ----------------------------------------------------------------------

    // 09:30 MMT side numbers — triggers at 10:02 Bangkok
    Schedule::command('twod:capture-side-numbers morning --max-attempts=3 --retry-interval=300')
        ->timezone('Asia/Bangkok')
        ->weekdays()
        ->withoutOverlapping(20)
        ->dailyAt('10:02')
        ->appendOutputTo(storage_path('logs/scheduler.log'));

    // 14:00 MMT side numbers — triggers at 14:32 Bangkok
    Schedule::command('twod:capture-side-numbers evening --max-attempts=3 --retry-interval=300')
        ->timezone('Asia/Bangkok')
        ->weekdays()
        ->withoutOverlapping(20)
        ->dailyAt('14:32')
        ->appendOutputTo(storage_path('logs/scheduler.log'));
} else {
    // 12:01 MMT slot — triggers at 12:31 Bangkok, 60-minute timeout, live fallback enabled
    Schedule::command('twod:fetch-and-settle 12:01 --timeout-minutes=60 --retry-interval=60')
        ->timezone('Asia/Bangkok')
        ->withoutOverlapping(70)
        ->dailyAt('12:31')
        ->appendOutputTo(storage_path('logs/scheduler.log'));

    // 16:30 MMT slot — triggers at 17:00 Bangkok (result appears at 5 PM), open_time stays 16:30 for bet lookup
    Schedule::command('twod:fetch-and-settle 16:30 --timeout-minutes=20 --retry-interval=30')
        ->timezone('Asia/Bangkok')
        ->withoutOverlapping(130)
        ->dailyAt('17:00')
        ->appendOutputTo(storage_path('logs/scheduler.log'));
}

// ---------------------------------------------------------------------------
// SET-index → Myanmar 2D capture (Mon–Fri, Asia/Bangkok). Stores only; does NOT
// settle bets. Closes fire a couple of minutes past the slot to let the value
// settle. The command also guards weekends/holidays via TradingCalendar.
// ---------------------------------------------------------------------------
foreach ([
    'morning_open' => '09:30',
    'morning_close' => '12:02',
    'afternoon_open' => '14:00',
    'evening_close' => '16:32',
] as $session => $at) {
    Schedule::command("set:capture {$session}")
        ->timezone('Asia/Bangkok')
        ->weekdays()
        ->withoutOverlapping(5)
        ->dailyAt($at)
        ->appendOutputTo(storage_path('logs/set-capture.log'));
}
