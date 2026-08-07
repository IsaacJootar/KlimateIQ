<?php

namespace App\Providers;

use App\Services\Ai\AiChatClient;
use App\Services\Ai\OpenAiClient;
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
        //
    }
}
