<?php

namespace App\Providers;

use App\Models\BidIncrementRule;
use App\Services\Bidding\IncrementCalculator;
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
        // Tiers are read once per request/job (scoped), then reused for every calculation.
        $this->app->scoped(IncrementCalculator::class, fn () => new IncrementCalculator(
            BidIncrementRule::query()->get(['from_amount', 'to_amount', 'increment'])->toArray(),
        ));
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
