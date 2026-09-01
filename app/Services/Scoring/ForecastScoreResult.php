<?php

namespace App\Services\Scoring;

use Illuminate\Support\Carbon;

/**
 * The output of ForecastScoringStrategy — the peak risk within the forecast window, plus when
 * it lands. Distinct from ScoreResult because an emergency planner needs the lead time, not
 * just the number.
 */
final class ForecastScoreResult
{
    /**
     * @param  array<string, mixed>  $breakdown  the per-day scored series + which signals fed
     *                                           it + the peak; empty when score is null.
     */
    public function __construct(
        public readonly ?float $score,
        public readonly ?Carbon $peakDate,
        public readonly ?int $leadDaysToPeak,
        public readonly int $horizonDays,
        public readonly array $breakdown,
        public readonly string $scoringVersion,
    ) {}
}
