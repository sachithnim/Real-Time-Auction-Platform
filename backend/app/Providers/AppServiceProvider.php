<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // Login/register: slow down password guessing per account and per client.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by('email:'.strtolower((string) $request->input('email'))),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);
    }
}
