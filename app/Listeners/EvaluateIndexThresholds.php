<?php

namespace App\Listeners;

use App\Events\RegionScoreCalculated;
use App\Services\Alerts\ThresholdEvaluationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class EvaluateIndexThresholds implements ShouldQueue
{
    public function __construct(private readonly ThresholdEvaluationService $evaluator) {}

    public function handle(RegionScoreCalculated $event): void
    {
        $this->evaluator->evaluateForIndex($event->indexId, $event->regionId, $event->periodStart, $event->score);
    }
}
