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
