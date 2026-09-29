<?php

namespace App\Providers;

use App\Services\AI\AiCredentialResolver;
use App\Services\AI\AIService;
use App\Services\Web\Providers\HttpWebExtractor;
use App\Services\Web\Providers\WebExtractorInterface;
use App\Services\Web\UrlContentExtractor;
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
        $this->app->singleton(AiCredentialResolver::class);

        $this->app->singleton(AIService::class, function ($app) {
            // The resolver is what makes BYOK work for every AI feature at
            // once: AIService is the single chokepoint all of them pass
            // through, so wiring it here covers capture, resume analysis and
            // resume matching without touching any of them.
            return new AIService(config('ai', []), $app->make(AiCredentialResolver::class));
        });

        // Pluggable web extraction: HttpWebExtractor fetches public pages
        // over plain HTTP. Tests swap in FakeWebExtractor via the container.
        $this->app->bind(WebExtractorInterface::class, HttpWebExtractor::class);

        $this->app->bind(UrlContentExtractor::class, function ($app) {
            return new UrlContentExtractor($app->make(WebExtractorInterface::class));
        });
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
