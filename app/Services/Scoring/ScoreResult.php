<?php

namespace App\Services\Scoring;

/**
 * The output of any ScoringStrategy — same shape regardless of which strategy produced it,
 * so the caller (RegionScoringService, the dashboard, reports) never needs to know which one ran.
 */
final class ScoreResult
{
    /**
     * @param  array<int, array<string, mixed>>  $breakdown  Per-signal trace: signal_type_code,
     *                                                       signal_type_name, raw_value, normalized_score, weight, vulnerability_multiplier,
     *                                                       contribution. Empty when score is null.
     */
    public function __construct(
        public readonly ?float $score,
        public readonly array $breakdown,
        public readonly string $scoringVersion,
    ) {}
}
