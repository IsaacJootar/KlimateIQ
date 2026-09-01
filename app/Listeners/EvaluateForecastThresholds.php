<?php

namespace App\Listeners;

use App\Events\RegionForecastScoreCalculated;
use App\Services\Alerts\ThresholdEvaluationService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * BUILD_PLAN.md T4 M4 — reacts to a fresh forecast score the way EvaluateIndexThresholds
 * reacts to an observed one. Auto-discovered from the typed event parameter.
 */
class EvaluateForecastThresholds implements ShouldQueue
{
    public function __construct(private readonly ThresholdEvaluationService $evaluator) {}

    public function handle(RegionForecastScoreCalculated $event): void
    {
        $this->evaluator->evaluateForForecast(
            $event->indexId,
            $event->regionId,
            $event->peakScore,
            $event->peakDate,
            $event->leadDaysToPeak,
        );
    }
}
