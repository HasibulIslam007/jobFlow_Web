<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
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
        $this->configureEloquentStrictness();
    }

    /**
     * Named rate limiters (docs/02-architecture.md §7.3).
     *
     * Reference them as middleware: throttle:api, throttle:auth, throttle:ai, throttle:uploads.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perHour(20)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }

    /**
     * Surface accidental N+1 queries during development and in the test suite;
     * production stays forgiving so a missed eager load never breaks a request.
     */
    private function configureEloquentStrictness(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
