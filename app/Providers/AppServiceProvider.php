<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('usage', function (\Illuminate\Http\Request $request) {
            $key = $request->header('X-API-KEY') ?: $request->ip();
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by($key);
        });
    }
}
