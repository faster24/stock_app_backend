<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('twod:fetch-live')
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping()
    ->dailyAt('11:00');

Schedule::command('twod:fetch-live')
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping()
    ->dailyAt('12:01');

Schedule::command('twod:fetch-live')
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping()
    ->dailyAt('15:00');

Schedule::command('twod:fetch-live')
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping()
    ->dailyAt('16:30');
