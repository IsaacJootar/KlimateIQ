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
 */
class RegionForecastScoringService
{
    public function __construct(private readonly ForecastScoringStrategy $strategy) {}

    public function calculate(ScoringIndex $index, Region $region, ?Carbon $issuedAt = null): ForecastScoreResult
    {
        $issuedAt = ($issuedAt ?? Carbon::now())->copy()->startOfDay();
        $result = $this->strategy->score($index, $region, $issuedAt);

        if ($result->score === null) {
            // No forecast coverage for this region (unmodelled reach) — leave any stale row in
            // place rather than writing a null score the UI would have to special-case anyway.
            return $result;
        }

        DB::table('region_forecast_scores')->upsert(
            [[
                'index_id' => $index->index_id,
                'region_id' => $region->region_id,
                'forecast_issued_at' => $issuedAt->toDateString(),
                'horizon_days' => $result->horizonDays,
                'score' => $result->score,
                'peak_date' => $result->peakDate?->toDateString(),
                'lead_days_to_peak' => $result->leadDaysToPeak,
                'scoring_strategy' => $this->strategy->code(),
                'scoring_version' => $result->scoringVersion,
                'breakdown' => json_encode($result->breakdown, JSON_THROW_ON_ERROR),
                'calculated_at' => now(),
            ]],
            ['index_id', 'region_id'],
            ['forecast_issued_at', 'horizon_days', 'score', 'peak_date', 'lead_days_to_peak', 'scoring_strategy', 'scoring_version', 'breakdown', 'calculated_at']
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
