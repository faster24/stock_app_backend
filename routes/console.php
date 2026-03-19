<?php

use App\Models\TwoDResult;
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

Schedule::call(function (): void {
    $result = TwoDResult::query()
        ->whereDate('stock_date', now('Asia/Bangkok')->toDateString())
        ->where('open_time', '11:00:00')
        ->latest('id')
        ->first();

    if ($result === null) {
        return;
    }

    Artisan::call('bets:settle-2d', [
        'history_id' => $result->history_id,
    ]);
})->timezone('Asia/Bangkok')->name('bets:settle-2d:11:00')->withoutOverlapping()->dailyAt('11:01');

Schedule::command('twod:fetch-live')
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping()
    ->dailyAt('12:01');

Schedule::call(function (): void {
    $result = TwoDResult::query()
        ->whereDate('stock_date', now('Asia/Bangkok')->toDateString())
        ->where('open_time', '12:01:00')
        ->latest('id')
        ->first();

    if ($result === null) {
        return;
    }

    Artisan::call('bets:settle-2d', [
        'history_id' => $result->history_id,
    ]);
})->timezone('Asia/Bangkok')->name('bets:settle-2d:12:01')->withoutOverlapping()->dailyAt('12:02');

Schedule::command('twod:fetch-live')
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping()
    ->dailyAt('15:00');

Schedule::call(function (): void {
    $result = TwoDResult::query()
        ->whereDate('stock_date', now('Asia/Bangkok')->toDateString())
        ->where('open_time', '15:00:00')
        ->latest('id')
        ->first();

    if ($result === null) {
        return;
    }

    Artisan::call('bets:settle-2d', [
        'history_id' => $result->history_id,
    ]);
})->timezone('Asia/Bangkok')->name('bets:settle-2d:15:00')->withoutOverlapping()->dailyAt('15:01');

Schedule::command('twod:fetch-live')
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping()
    ->dailyAt('16:30');

Schedule::call(function (): void {
    $result = TwoDResult::query()
        ->whereDate('stock_date', now('Asia/Bangkok')->toDateString())
        ->where('open_time', '16:30:00')
        ->latest('id')
        ->first();

    if ($result === null) {
        return;
    }

    Artisan::call('bets:settle-2d', [
        'history_id' => $result->history_id,
    ]);
})->timezone('Asia/Bangkok')->name('bets:settle-2d:16:30')->withoutOverlapping()->dailyAt('16:31');
