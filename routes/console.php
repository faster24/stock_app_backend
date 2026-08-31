<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
// Retry cadence. The settlement slots poll every 20s and let
// --timeout-minutes govern the window; there is deliberately no --max-attempts.
//
// They used to run --retry-interval=300 --max-attempts=4, sized for a metered
// 100/day test key that is long retired. HTAYAPI_DAILY_LIMIT is 8000 and a
// measured day spends ~34 calls, so the sparse cadence bought nothing and cost
// five minutes: upstream still serves the PREVIOUS day's number at the
// publication instant, so attempt 1 is always rejected as carry-over by
// HtayApiFreshnessGuard, and the next look was 300s later. Every draw landed at
// 16:35 (and 12:06) to the second — the lag was our sampling interval, not
// upstream. Worst case now is ~60 calls per slot.
//
// --retry-interval and --max-attempts must be changed TOGETHER. A tight
// interval with the old cap of 4 would close the window 80 seconds after the
// slot opens and lose the day outright.
//
// This does not help the ~1% of days whose number repeats the previous trading
// day's: HtayApiFreshnessGuard falls back to a value comparison for its first
// CARRY_OVER_GRACE_MINUTES, so those settle at slot+10 no matter how often we
// poll. That is intended — the alternative silently drops the day.
//
// Branching here — not inside
// FetchAndSettleTwoDCommand — keeps the command provider-agnostic and means
// the same TWOD_DRIVER flip that selects the provider also selects safe
// scheduling, with no separate env variable to remember.
//
// Both slots are ->weekdays(): there is no draw on Sat/Sun, so without the
// guard each weekend day burnt a full retry budget and closed with a CRITICAL
// timeout log — masking real weekday failures behind routine weekend noise.
if (config('services.twod.driver') === 'htayapi') {
    // 12:01 MMT slot — triggers at 12:31 Bangkok
    Schedule::command('twod:fetch-and-settle 12:01 --timeout-minutes=20 --retry-interval=20')
        ->timezone('Asia/Bangkok')
        ->weekdays()
        ->withoutOverlapping(30)
        ->dailyAt('12:31')
        ->appendOutputTo(storage_path('logs/scheduler.log'));

    // 16:30 MMT slot — triggers at 17:00 Bangkok
    Schedule::command('twod:fetch-and-settle 16:30 --timeout-minutes=20 --retry-interval=20')
        ->timezone('Asia/Bangkok')
        ->weekdays()
        ->withoutOverlapping(30)
        ->dailyAt('17:00')
        ->appendOutputTo(storage_path('logs/scheduler.log'));

    // ----------------------------------------------------------------------
    // Side numbers (modern/internet). Display only — these never settle bets.
    // htayapi-only: no other provider carries these fields.
    //
    // Same MMT+30 rule as above, plus 7 minutes. The old +2 offset put attempt 1
    // at 09:32 MMT, which measurably beat publication on 8 of 11 observed days —
    // a third of the retry budget spent every day on a read that could not
    // succeed. Starting at 09:37 buys those attempts back.
    //
    // Twelve attempts 5 minutes apart carries the window to 10:32 MMT. It used
    // to close at 09:42: on 2026-08-28 upstream published a few minutes late and
    // the day was lost outright, because htayapi serves no history and a missed
    // slot can never be backfilled. Width is the entire defence.
    //
    // The late sweep exists for the case the primary run still loses — the
    // 2026-08-20/21 upstream outage took out both slots on both days. It costs
    // ZERO upstream calls on a normal day: `already_captured` is checked before
    // the fetch, so a complete row makes the whole run a no-op.
    //
    // Budget: worst case 18 calls per slot per day, 36 total, against
    // HTAYAPI_DAILY_LIMIT of 8000. The old 25/day ceiling in this comment
    // referred to a retired 100/day test key. Typical day is ~4.
    //
    // Any widening here must be matched in
    // services.twod.side_number_carry_over_grace_minutes — see that comment.
    // ----------------------------------------------------------------------

    // 09:30 MMT side numbers — triggers at 10:07 Bangkok, last attempt 11:02
    Schedule::command('twod:capture-side-numbers morning --max-attempts=12 --retry-interval=300')
        ->timezone('Asia/Bangkok')
        ->weekdays()
        ->withoutOverlapping(70)
        ->dailyAt('10:07')
        ->appendOutputTo(storage_path('logs/scheduler.log'));

    // 14:00 MMT side numbers — triggers at 14:37 Bangkok, last attempt 15:32
    Schedule::command('twod:capture-side-numbers evening --max-attempts=12 --retry-interval=300')
        ->timezone('Asia/Bangkok')
        ->weekdays()
        ->withoutOverlapping(70)
        ->dailyAt('14:37')
        ->appendOutputTo(storage_path('logs/scheduler.log'));

    // Late sweeps. No-ops unless the primary run above came away empty.
    Schedule::command('twod:capture-side-numbers morning --max-attempts=6 --retry-interval=600')
        ->timezone('Asia/Bangkok')
        ->weekdays()
        ->withoutOverlapping(60)
        ->dailyAt('12:00')
        ->appendOutputTo(storage_path('logs/scheduler.log'));

    Schedule::command('twod:capture-side-numbers evening --max-attempts=6 --retry-interval=600')
        ->timezone('Asia/Bangkok')
        ->weekdays()
        ->withoutOverlapping(60)
        ->dailyAt('16:00')
        ->appendOutputTo(storage_path('logs/scheduler.log'));
} else {
    // 12:01 MMT slot — triggers at 12:31 Bangkok, 60-minute timeout, live fallback enabled
    Schedule::command('twod:fetch-and-settle 12:01 --timeout-minutes=60 --retry-interval=60')
        ->timezone('Asia/Bangkok')
        ->weekdays()
        ->withoutOverlapping(70)
        ->dailyAt('12:31')
        ->appendOutputTo(storage_path('logs/scheduler.log'));

    // 16:30 MMT slot — triggers at 17:00 Bangkok (result appears at 5 PM), open_time stays 16:30 for bet lookup
    Schedule::command('twod:fetch-and-settle 16:30 --timeout-minutes=20 --retry-interval=30')
        ->timezone('Asia/Bangkok')
        ->weekdays()
        ->withoutOverlapping(130)
        ->dailyAt('17:00')
        ->appendOutputTo(storage_path('logs/scheduler.log'));
}

// ---------------------------------------------------------------------------
// Queue-worker heartbeat. Notification delivery depends entirely on a worker
// consuming the `notifications` queue; when that worker dies, pushes stop
// silently while everything else (balances, API responses) keeps working. This
// turns that silent failure into a log line.
// ---------------------------------------------------------------------------
$queueBacklogCheck = Schedule::call(function () {
    $depth = DB::table('jobs')->count();
    $oldestAvailableAt = DB::table('jobs')->min('available_at');
    $oldestAgeSeconds = $oldestAvailableAt !== null ? now()->timestamp - (int) $oldestAvailableAt : 0;

    if ($depth > 50 || $oldestAgeSeconds > 300) {
        Log::error("Queue backlog: {$depth} job(s), oldest {$oldestAgeSeconds}s old — is the queue worker running?");
    }
})->everyFiveMinutes()->name('queue-backlog-check')->withoutOverlapping();

// ---------------------------------------------------------------------------
// Scheduler dead-man's switch. Everything else here reports a failure by
// logging it — which only works if the scheduler is running to do the logging.
// When cron itself dies, settlement silently stops and nothing anywhere says
// so. This hangs a ping on the most frequent job in the file, so an external
// watchdog alerts on the absence of a signal rather than the presence of one.
//
// The two settlement slots need no ping of their own: FetchAndSettleTwoDCommand
// already logs CRITICAL when it gives up, and that now reaches the alert
// channel. Missing scheduler is the gap this closes.
// ---------------------------------------------------------------------------
if (filled($healthcheckPingUrl = config('services.healthchecks.ping_url'))) {
    $queueBacklogCheck
        ->pingOnSuccess("{$healthcheckPingUrl}/scheduler-heartbeat")
        ->pingOnFailure("{$healthcheckPingUrl}/scheduler-heartbeat/fail");
}
