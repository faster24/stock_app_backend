<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('twod:fetch-and-settle', ['open_time' => '12:01', '--timeout-minutes' => 60, '--no-live-fallback' => true])
    ->timezone('Asia/Yangon')
    ->withoutOverlapping(70)
    ->dailyAt('12:01')
    ->appendOutputTo(storage_path('logs/scheduler.log'));

Schedule::command('twod:fetch-and-settle', ['open_time' => '14:30', '--timeout-minutes' => 20])
    ->timezone('Asia/Yangon')
    ->withoutOverlapping(130)
    ->dailyAt('14:30')
    ->appendOutputTo(storage_path('logs/scheduler.log'));
