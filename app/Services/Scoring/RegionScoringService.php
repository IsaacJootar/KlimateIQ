<?php

namespace App\Services\Scoring;

use App\Events\RegionScoreCalculated;
use App\Models\Region;
use App\Models\ScoringIndex;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates one index/region/period score: resolves which strategy should run, runs it,
 * and upserts the result. Composite-key writes go through DB::table()->upsert(), not Eloquent
 * save() — Eloquent only scopes updates by a single primary key column, which would silently
 * clobber every region_scores row sharing that index_id.
 */
class RegionScoringService
{
    public function __construct(private readonly ScoringStrategyResolver $resolver) {}

    public function calculate(ScoringIndex $index, Region $region, Carbon $periodStart, Carbon $periodEnd): ScoreResult
    {
        $strategy = $this->resolver->resolve($region, $index);
        $result = $strategy->score($index, $region, $periodStart, $periodEnd);
        $calculatedAt = now();

        DB::table('region_scores')->upsert(
            [[
                'index_id' => $index->index_id,
                'region_id' => $region->region_id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'score' => $result->score,
                'scoring_strategy' => $strategy->code(),
                'scoring_version' => $result->scoringVersion,
                'breakdown' => json_encode($result->breakdown, JSON_THROW_ON_ERROR),
                'calculated_at' => $calculatedAt,
            ]],
            ['index_id', 'region_id', 'period_start'],
            ['period_end', 'score', 'scoring_strategy', 'scoring_version', 'breakdown', 'calculated_at']
        );

        RegionScoreCalculated::dispatch($index->index_id, $region->region_id, $periodStart->toDateString(), $result->score);

        return $result;
    }
}
