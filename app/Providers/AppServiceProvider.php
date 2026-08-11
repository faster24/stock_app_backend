<?php

namespace App\Providers;

use App\Contracts\SetScraper;
use App\Contracts\ThreeDHistoryProvider;
use App\Contracts\ThreeDLiveProvider;
use App\Contracts\TwoDLiveProvider;
use App\Events\BetPaidOutEvent;
use App\Events\BetWonEvent;
use App\Events\DepositApprovedEvent;
use App\Events\DepositRejectedEvent;
use App\Events\SettlementRevertedEvent;
use App\Events\WithdrawalCompletedEvent;
use App\Events\WithdrawalRejectedEvent;
use App\Listeners\SendBetPaidOutNotification;
use App\Listeners\SendBetWonNotification;
use App\Listeners\SendDepositApprovedNotification;
use App\Listeners\SendDepositRejectedNotification;
use App\Listeners\SendSettlementRevertedNotification;
use App\Listeners\SendWithdrawalCompletedNotification;
use App\Listeners\SendWithdrawalRejectedNotification;
use App\Services\FirebaseNotificationService;
use App\Services\Set\NodeSetScraper;
use App\Services\ThreeD\HtayApiThreeDHistoryProvider;
use App\Services\ThreeD\HtayApiThreeDLiveProvider;
use App\Services\TwoD\HtayApiCallBudget;
use App\Services\TwoD\TwoDLiveProviderManager;
use App\Support\RealSleeper;
use App\Support\Sleeper;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Sleeper::class, RealSleeper::class);

        // Singleton so a queue worker performs the Google OAuth token exchange
        // once per process instead of once per notification.
        $this->app->singleton(FirebaseNotificationService::class);

        $this->app->singleton(TwoDLiveProviderManager::class);
        $this->app->bind(
            TwoDLiveProvider::class,
            fn ($app) => $app->make(TwoDLiveProviderManager::class)->driver(),
        );

        // Read-only 3D history. Same vendor and key as the 2D live ticker, so it
        // shares HtayApiCallBudget's daily ceiling.
        $this->app->bind(ThreeDHistoryProvider::class, function ($app) {
            $config = (array) $app['config']->get('services.twod.htayapi', []);

            return new HtayApiThreeDHistoryProvider(
                (string) ($config['threed_history_url'] ?? ''),
                (string) ($config['key'] ?? ''),
                (int) ($config['timeout'] ?? 20),
                new HtayApiCallBudget((int) ($config['daily_limit'] ?? 25)),
            );
        });

        $this->app->bind(ThreeDLiveProvider::class, function ($app) {
            $config = (array) $app['config']->get('services.twod.htayapi', []);

            return new HtayApiThreeDLiveProvider(
                (string) ($config['threed_live_url'] ?? ''),
                (string) ($config['key'] ?? ''),
                (int) ($config['timeout'] ?? 20),
                new HtayApiCallBudget((int) ($config['daily_limit'] ?? 25)),
            );
        });

        $this->app->bind(
            SetScraper::class,
            fn ($app) => new NodeSetScraper((array) $app['config']->get('set', [])),
        );
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
        Event::listen(WithdrawalRejectedEvent::class, SendWithdrawalRejectedNotification::class);
        Event::listen(SettlementRevertedEvent::class, SendSettlementRevertedNotification::class);
    }
}
