<?php

namespace App\Providers;

use App\Services\Ai\AiChatClient;
use App\Services\Ai\OpenAiClient;
use App\Services\Earthdata\AppEearsClient;
use App\Services\Sms\SmsClient;
use App\Services\Sms\TermiiSmsClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // AI summaries use OpenAI (ChatGPT), bound behind a provider-agnostic interface so it
        // can be swapped without touching RegionScoreSummaryService.
        $this->app->singleton(AiChatClient::class, fn () => OpenAiClient::fromConfig());

        // SMS alerts use Termii, bound the same way — SmsChannel never knows which provider
        // is behind SmsClient.
        $this->app->singleton(SmsClient::class, fn () => TermiiSmsClient::fromConfig());

        // NASA Earthdata (AppEEARS) — used by VegetationIngestionService.
        $this->app->singleton(AppEearsClient::class, fn () => AppEearsClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     *
     * App\Listeners\EvaluateIndexThresholds and EvaluateSignalThresholds are wired to their
     * events (App\Events\RegionScoreCalculated / RegionSignalIngested) automatically by
     * Laravel's event auto-discovery — no manual Event::listen() needed, and adding one here
     * would double-register them. The alerts layer only ever reacts to those events; it never
     * calls into scoring or ingestion directly, so either side can be deployed or scaled
     * independently.
     */
    public function boot(): void
    {
        // Third-party API (routes/api.php) — keyed by the authenticated user, not the IP, so
        // one integration's traffic can't eat another token holder's quota. 60/min is generous
        // for a dashboard-style read integration; this is insurance against a runaway client,
        // not a tight production limit.
        RateLimiter::for('api', fn ($request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
    }
}
