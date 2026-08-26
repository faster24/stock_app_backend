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
use App\Events\DepositRequestedEvent;
use App\Events\SettlementRevertedEvent;
use App\Events\WithdrawalCompletedEvent;
use App\Events\WithdrawalRejectedEvent;
use App\Events\WithdrawalRequestedEvent;
use App\Listeners\ScheduleAdminPendingRequestsNotification;
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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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

        // Everything that lands on an admin's queue funnels into one debounced,
        // aggregated push. BetWonEvent counts because a settled winner still
        // needs a manual payout.
        Event::listen(DepositRequestedEvent::class, ScheduleAdminPendingRequestsNotification::class);
        Event::listen(WithdrawalRequestedEvent::class, ScheduleAdminPendingRequestsNotification::class);
        Event::listen(BetWonEvent::class, ScheduleAdminPendingRequestsNotification::class);

        $this->configureRateLimiting();
    }

    /**
     * Laravel 11 ships no RouteServiceProvider, so nothing defines these unless
     * we do -- and `throttle:api` on an undefined limiter is a hard error, not a
     * no-op.
     *
     * Every per-IP number here is deliberately loose. Myanmar mobile carriers
     * put large numbers of subscribers behind one CGNAT address, so limits tight
     * enough to look impressive would lock out real players sharing an IP with
     * an attacker. The goal is to turn an unlimited firehose into a trickle, not
     * to price out a determined single attacker.
     */
    private function configureRateLimiting(): void
    {
        // Two windows: the per-minute cap stops a burst, the per-hour cap stops
        // a slow drip that would stay under it all day.
        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(20)->by($request->ip()),
        ]);

        RateLimiter::for('login', fn (Request $request) => [
            // Keyed on the credential pair, not the IP alone: an attacker
            // hammering someone else's account must not be able to lock the
            // owner out of it.
            Limit::perMinute(5)->by($request->ip().'|'.$request->input('email')),
            // And a looser ceiling so spraying across many accounts from one
            // source still runs out of budget.
            Limit::perMinute(30)->by($request->ip()),
        ]);

        // Authenticated traffic is keyed per user, so one noisy device cannot
        // spend everyone else's budget. Generous: both clients poll.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
