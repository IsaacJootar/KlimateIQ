<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever RegionForecastScoringService persists a forward score — the forecast
 * counterpart of RegionScoreCalculated, and the seam the forecast-breach alert path (T4 M4)
 * reacts to. Carries the peak and its lead time, which is what a forecast alert needs.
 */
class RegionForecastScoreCalculated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $indexId,
        public readonly int $regionId,
        public readonly string $forecastIssuedAt,
        public readonly ?float $peakScore,
        public readonly ?string $peakDate,
        public readonly ?int $leadDaysToPeak,
    ) {}
}
