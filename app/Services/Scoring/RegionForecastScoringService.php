<?php

namespace App\Services\Scoring;

use App\Events\RegionForecastScoreCalculated;
use App\Models\Region;
use App\Models\ScoringIndex;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates one forward index/region score (BUILD_PLAN.md T4). The forecast counterpart of
 * RegionScoringService: runs ForecastScoringStrategy, upserts the result into
 * region_forecast_scores (composite-key write via DB::table(), not Eloquent), fires the event
 * the alert layer listens on. One row per (index, region) — the current forecast.
 *
 * T5: after the deterministic (control) score, a second pass scores the ensemble members and
 * folds the p10/p50/p90 + exceedance probability into the same row. The control `score` /
 * `peak_date` / `lead_days_to_peak` are untouched — the distribution is strictly additive.
 */
class RegionForecastScoringService
{
    public function __construct(
        private readonly ForecastScoringStrategy $strategy,
        private readonly EnsembleForecastScoringService $ensemble,
    ) {}

    public function calculate(ScoringIndex $index, Region $region, ?Carbon $issuedAt = null, bool $withEnsemble = true): ForecastScoreResult
    {
        $issuedAt = ($issuedAt ?? Carbon::now())->copy()->startOfDay();
        $result = $this->strategy->score($index, $region, $issuedAt);

        if ($result->score === null) {
            // No forecast coverage for this region (unmodelled reach) — leave any stale row in
            // place rather than writing a null score the UI would have to special-case anyway.
            return $result;
        }

        $dist = $withEnsemble ? $this->ensemble->distribution($index, $region, $issuedAt) : null;

        $breakdown = $result->breakdown;
        if ($dist !== null) {
            $breakdown['members'] = $dist->memberPeaks;
            $breakdown['member_daily'] = $dist->memberDaily;
        }

        DB::table('region_forecast_scores')->upsert(
            [[
                'index_id' => $index->index_id,
                'region_id' => $region->region_id,
                'forecast_issued_at' => $issuedAt->toDateString(),
                'horizon_days' => $result->horizonDays,
                'score' => $result->score,
                'p10' => $dist?->p10,
                'p50' => $dist?->p50,
                'p90' => $dist?->p90,
                'exceedance_probability' => $dist?->exceedanceProbability,
                'exceedance_reference' => $dist?->exceedanceReference,
                'member_count' => $dist?->memberCount,
                'peak_date' => $result->peakDate?->toDateString(),
                'lead_days_to_peak' => $result->leadDaysToPeak,
                'scoring_strategy' => $this->strategy->code(),
                'scoring_version' => $result->scoringVersion,
                'breakdown' => json_encode($breakdown, JSON_THROW_ON_ERROR),
                'calculated_at' => now(),
            ]],
            ['index_id', 'region_id'],
            ['forecast_issued_at', 'horizon_days', 'score', 'p10', 'p50', 'p90', 'exceedance_probability', 'exceedance_reference', 'member_count', 'peak_date', 'lead_days_to_peak', 'scoring_strategy', 'scoring_version', 'breakdown', 'calculated_at']
        );

        RegionForecastScoreCalculated::dispatch(
            $index->index_id,
            $region->region_id,
            $issuedAt->toDateString(),
            $result->score,
            $result->peakDate?->toDateString(),
            $result->leadDaysToPeak,
        );

        return $result;
    }
}
