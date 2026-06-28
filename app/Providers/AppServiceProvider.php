<?php

namespace App\Providers;

use App\Events\BetPaidOutEvent;
use App\Events\BetWonEvent;
use App\Events\DepositApprovedEvent;
use App\Events\DepositRejectedEvent;
use App\Events\WithdrawalCompletedEvent;
use App\Listeners\SendBetPaidOutNotification;
use App\Listeners\SendBetWonNotification;
use App\Listeners\SendDepositApprovedNotification;
use App\Listeners\SendDepositRejectedNotification;
use App\Listeners\SendWithdrawalCompletedNotification;
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
        Event::listen(DepositApprovedEvent::class, SendDepositApprovedNotification::class);
        Event::listen(DepositRejectedEvent::class, SendDepositRejectedNotification::class);
        Event::listen(WithdrawalCompletedEvent::class, SendWithdrawalCompletedNotification::class);
    }
}
