<?php

namespace App\Support;

use App\Models\Region;
use App\Models\RegionForecastScore;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use Illuminate\Support\Carbon;

/**
 * The one place that answers "what is the current headline number for this region and index",
 * reading from the observed lane or the forecast lane depending on the index (BUILD_PLAN.md T4).
 * A forecast index has no observed `region_scores` row — its headline is the forecast peak and
 * how many days out it lands.
 */
class LatestScore
{
    /**
     * @return array{score: ?float, band: string, as_of: ?Carbon, is_forecast: bool, lead_days: ?int, target_date: ?Carbon}|null
     */
    public static function for(Region|int $region, ScoringIndex $index): ?array
    {
        $regionId = $region instanceof Region ? $region->region_id : $region;

        if ($index->is_forecast) {
            $row = RegionForecastScore::query()
                ->where('index_id', $index->index_id)
                ->where('region_id', $regionId)
                ->first();

            if ($row === null) {
                return null;
            }

            return [
                'score' => (float) $row->score,
                'band' => RiskBand::forScore((float) $row->score),
                'as_of' => $row->forecast_issued_at,
                'is_forecast' => true,
                'lead_days' => $row->lead_days_to_peak,
                'target_date' => $row->peak_date,
            ];
        }

        $row = RegionScore::query()
            ->where('index_id', $index->index_id)
            ->where('region_id', $regionId)
            ->orderByDesc('period_start')
            ->first();

        if ($row === null) {
            return null;
        }

        $score = $row->score !== null ? (float) $row->score : null;

        return [
            'score' => $score,
            'band' => RiskBand::forScore($score),
            'as_of' => $row->period_start,
            'is_forecast' => false,
            'lead_days' => null,
            'target_date' => null,
        ];
    }
}
