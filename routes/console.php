<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('twod:fetch-and-settle', ['open_time' => '12:01'])
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping(130)
    ->dailyAt('12:01');

Schedule::command('twod:fetch-and-settle', ['open_time' => '16:30'])
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping(130)
    ->dailyAt('16:30');
