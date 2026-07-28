<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 12:01 slot — 60-minute timeout, live fallback enabled
Schedule::command('twod:fetch-and-settle 12:01 --timeout-minutes=60 --retry-interval=60')
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping(70)
    ->dailyAt('12:01')
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// 16:30 slot — triggers at 17:00 Bangkok (result appears at 5 PM), open_time stays 16:30 for bet lookup
Schedule::command('twod:fetch-and-settle 16:30 --timeout-minutes=20 --retry-interval=30')
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping(130)
    ->dailyAt('17:00')
    ->appendOutputTo(storage_path('logs/scheduler.log'));

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
