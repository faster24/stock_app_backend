<?php

namespace App\Providers;

use App\Support\RealSleeper;
use App\Support\Sleeper;
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
        //
    }
}
