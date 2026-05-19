<?php

namespace App\Providers;

use App\Events\BetPaidOutEvent;
use App\Events\BetWonEvent;
use App\Listeners\SendBetPaidOutNotification;
use App\Listeners\SendBetWonNotification;
use App\Support\RealSleeper;
use App\Support\Sleeper;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Sleeper::class, RealSleeper::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(BetWonEvent::class, SendBetWonNotification::class);
        Event::listen(BetPaidOutEvent::class, SendBetPaidOutNotification::class);
    }
}
