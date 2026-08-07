<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever RegionScoringService persists a score — the seam between the scoring layer
 * and the alerts layer. The alerts layer only ever reacts to this event; it never calls into
 * scoring directly, so either side can be deployed, scaled, or replaced independently.
 */
class RegionScoreCalculated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $indexId,
        public readonly int $regionId,
        public readonly string $periodStart,
        public readonly ?float $score,
    ) {}
}
