<?php

namespace App\Support;

/**
 * The always-present, deterministic link between a raw score and "what does this actually
 * mean" — no AI call, nothing to configure, derived only from the same breakdown the drivers
 * list already shows, so it can never say more than the numbers prove. The optional AI Summary
 * is a deliberate extra a user clicks for; this is what's shown by default.
 *
 * Clarity Pass A4 — the conclusion is now two or three short sentences a non-analyst reads
 * once, and the top drivers come back as structured data so the view doesn't recompute them.
 */
class ScoreDiagnosis
{
    private const BAND_PLAIN = ['green' => 'Low risk', 'amber' => 'Moderate risk', 'red' => 'High risk'];

    /**
     * @param  array<int, array<string, mixed>>  $breakdown
     * @param  array<string, string>  $signalLabels  code => reader-facing name; falls back to the code
     * @param  ?string  $trendDirection  'up' | 'down' | 'flat' from TrendSummary, for the headline
     * @return array{
     *     dominantSignal: ?string,
     *     dominantContribution: ?float,
     *     headline: ?string,
     *     conclusion: ?string,
     *     drivers: array<int, array{code: string, label: string, points: float, share: int}>,
     * }
     */
    public static function forBreakdown(array $breakdown, ?float $score, array $signalLabels = [], ?string $trendDirection = null): array
    {
        $empty = [
            'dominantSignal' => null,
            'dominantContribution' => null,
            'headline' => null,
            'conclusion' => null,
            'drivers' => [],
        ];

        // Older stored breakdowns only carry `contribution` (pre-weight-renormalisation); newer
        // ones add `contribution_to_final_score` (each signal's true share of the score).
        $contrib = fn (array $row) => (float) ($row['contribution_to_final_score'] ?? $row['contribution'] ?? 0);

        $available = collect($breakdown)
            ->reject(fn (array $row) => ($row['status'] ?? null) === 'no_data')
            ->filter(fn (array $row) => $contrib($row) > 0)
            ->sortByDesc($contrib)
            ->values();

        if ($score === null || $available->isEmpty()) {
            return $empty;
        }

        $label = fn (array $row) => $signalLabels[$row['signal_type_code']] ?? $row['signal_type_name'] ?? $row['signal_type_code'];

        $drivers = $available->map(fn (array $row) => [
            'code' => $row['signal_type_code'],
            'label' => $label($row),
            'points' => round($contrib($row), 1),
            'share' => (int) round(($contrib($row) / max($score, 0.01)) * 100),
        ])->take(4)->all();

        $band = RiskBand::forScore($score);
        $bandPlain = self::BAND_PLAIN[$band] ?? 'Risk';
        $top = $drivers[0];

        // Headline: band + where it's going.
        $direction = match ($trendDirection) {
            'up' => ' and building', 'down' => ' and easing', 'flat' => ' and steady',
            default => '',
        };
        $headline = "{$bandPlain} this week{$direction}.";

        // Conclusion: the main driver, then whether the others agree.
        $sentences = ["The main reason is {$top['label']} — about {$top['share']}% of the score."];

        $others = array_slice($drivers, 1, 2);
        $agreeing = array_filter($others, fn ($d) => $d['share'] >= 15);

        if (count($agreeing) >= 1) {
            $names = implode(' and ', array_map(fn ($d) => $d['label'], $agreeing));
            $sentences[] = count($agreeing) === 1
                ? "{$names} is pushing the same way."
                : "{$names} are pushing the same way.";
        } elseif ($others !== []) {
            $sentences[] = 'The other signals are near normal.';
        }

        return [
            'dominantSignal' => $top['label'],
            'dominantContribution' => $top['points'],
            'headline' => $headline,
            'conclusion' => implode(' ', $sentences),
            'drivers' => $drivers,
        ];
    }
}
