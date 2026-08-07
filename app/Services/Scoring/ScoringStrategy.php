<?php

namespace App\Services\Scoring;

use App\Models\Region;
use App\Models\ScoringIndex;
use Illuminate\Support\Carbon;

/**
 * One way of turning a region's signals into a 0-100 score for a named index.
 *
 * WeightedFormulaScoringStrategy and TrainedModelScoringStrategy both implement this, and
 * RegionScoringService picks between them at runtime via config('scoring.strategy') or a
 * region's own preferred_scoring_strategy override — nothing downstream (alerts, dashboard,
 * reports) needs to know or care which one actually ran.
 */
interface ScoringStrategy
{
    /**
     * The scoring_strategy value stored on region_scores rows this strategy produces
     * (e.g. 'formula', 'trained_model').
     */
    public function code(): string;

    /**
     * Whether this strategy can currently score (e.g. the trained model has been trained and
     * dropped in place). RegionScoringService falls back to the formula strategy when false.
     */
    public function isAvailable(Region $region, ScoringIndex $index): bool;

    public function score(ScoringIndex $index, Region $region, Carbon $periodStart, Carbon $periodEnd): ScoreResult;
}
