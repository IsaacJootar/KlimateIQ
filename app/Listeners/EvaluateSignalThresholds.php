<?php

namespace App\Listeners;

use App\Events\RegionSignalIngested;
use App\Services\Alerts\ThresholdEvaluationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class EvaluateSignalThresholds implements ShouldQueue
{
    public function __construct(private readonly ThresholdEvaluationService $evaluator) {}

    public function handle(RegionSignalIngested $event): void
    {
        $this->evaluator->evaluateForSignal($event->signalTypeId, $event->regionId, $event->periodStart, $event->value);
    }
}
