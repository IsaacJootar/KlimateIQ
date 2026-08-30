<?php

namespace App\Support;

/**
 * The always-present link between a raw score and "what does this actually mean" — the AI
 * Summary is a deliberate optional extra a user has to click for, so without this, a score with
 * no summary generated yet has no plain-English conclusion at all, just a breakdown table. This
 * is deterministic (no AI call, nothing to configure) and derived only from the same breakdown
 * the table already shows, so it can never say more than the numbers already prove.
 */
class ScoreDiagnosis
{
    private const BAND_LABELS = ['green' => 'low', 'amber' => 'moderate', 'red' => 'high'];

    /**
     * @param  array<int, array<string, mixed>>  $breakdown
     * @param  array<string, string>  $signalLabels  code => reader-facing name; falls back to the code
     * @return array{dominantSignal: ?string, dominantContribution: ?float, conclusion: ?string}
     */
    public static function forBreakdown(array $breakdown, ?float $score, array $signalLabels = []): array
    {
        $available = collect($breakdown)->reject(fn (array $row) => ($row['status'] ?? null) === 'no_data');

        if ($score === null || $available->isEmpty()) {
            return ['dominantSignal' => null, 'dominantContribution' => null, 'conclusion' => null];
        }

        $dominant = $available->sortByDesc(fn (array $row) => $row['contribution_to_final_score'] ?? 0)->first();
        $bandLabel = self::BAND_LABELS[RiskBand::forScore($score)] ?? 'unknown';
        $formattedScore = rtrim(rtrim(number_format($score, 1), '0'), '.');

        $code = $dominant['signal_type_code'];
        $label = $signalLabels[$code] ?? $dominant['signal_type_name'] ?? $code;

        return [
            'dominantSignal' => $label,
            'dominantContribution' => $dominant['contribution_to_final_score'] ?? null,
            'conclusion' => "This is a {$bandLabel}-risk score, driven mainly by {$label} ".
                "({$dominant['contribution_to_final_score']} of the {$formattedScore} points).",
        ];
    }
}
