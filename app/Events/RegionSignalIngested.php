<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever a signal source successfully ingests a reading — lets a per-signal threshold
 * (e.g. "alert if standing water > 70 in Bayelsa") fire independently of composite-score
 * thresholds, without the alerts layer depending on the scoring layer at all.
 */
class RegionSignalIngested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $signalTypeId,
        public readonly int $regionId,
        public readonly string $periodStart,
        public readonly float $value,
    ) {}
}
