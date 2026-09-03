<?php

namespace App\Services\Scoring;

/**
 * The output of EnsembleForecastScoringService (BUILD_PLAN.md T5) — the distribution of the
 * index's peak-within-horizon score across the ensemble members. `memberPeaks` is the sorted
 * per-member peak score; every percentile and probability the product needs is read off it.
 */
final class EnsembleScoreResult
{
    /**
     * @param  list<float>  $memberPeaks  sorted ascending
     * @param  list<array{date: string, lead_days: int, p10: float, p50: float, p90: float}>  $memberDaily
     */
    public function __construct(
        public readonly float $p10,
        public readonly float $p50,
        public readonly float $p90,
        public readonly float $exceedanceProbability,
        public readonly float $exceedanceReference,
        public readonly int $memberCount,
        public readonly array $memberPeaks,
        public readonly array $memberDaily,
    ) {}
}
