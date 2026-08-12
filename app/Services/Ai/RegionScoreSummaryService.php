<?php

namespace App\Services\Ai;

use App\Models\RegionScore;
use App\Support\RiskBand;
use RuntimeException;

/**
 * Turns a region score's structured breakdown into a short plain-English summary for the
 * dashboard and reports.
 *
 * Non-negotiable boundary: this may only restate what is already in the breakdown. It never
 * introduces a number, signal, or claim that isn't already computed and stored — the
 * deterministic scoring engine is the source of truth, this is prose.
 */
class RegionScoreSummaryService
{
    private const BASE_RULES = <<<'PROMPT'
    You write short diagnostic summaries of a climate-health risk score for a Nigerian LGA
    (local government area). You are given the region's name, the named index it belongs to,
    its current score (0-100) and risk band, and a per-signal breakdown as structured data —
    including each signal's true share of the final score ("contribution to final score").
    Your job is to turn that structure into clear, plain prose for a health officer or
    emergency response coordinator deciding what to do next.

    Structure the summary as two parts, in plain sentences (not headings or bullet points):
    1. Name the risk band and the signal(s) that actually drove the score — use the
       "contribution to final score" figures to say which signal mattered most, not just
       which had the highest raw value. If one or two signals clearly dominate, say so by name.
    2. State plainly what that combination of signals typically indicates for this index, using
       only what's already implied by the index name and the data given — not a prediction of
       future events, just what the current reading represents right now.

    Hard rules, without exception:
    - Never introduce a signal, value, weight, or contribution that is not present in the data
      you were given. If it is not in the data, it does not exist.
    - Do not predict the future.
    - Do not give clinical or medical treatment advice.
    - Every claim you make must trace to an item in the data.
    - Plain language, no jargon, 3-5 short sentences.
    - Do not invent region names, dates, or context beyond what's given.
    - A signal with a real raw value of zero is a genuine measurement, not missing data —
      describe what was actually found (e.g. "no standing water was detected nearby"), never
      phrase it as the signal being excluded, skipped, or "not counting" toward the score.
      Reserve language like "unavailable" or "no data" strictly for signals explicitly marked
      "no data available this period" in the input.
    PROMPT;

    public function __construct(private readonly AiChatClient $client) {}

    public function isAvailable(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @return array{body: string, model: string}
     *
     * @throws RuntimeException when unavailable or the API call fails.
     */
    public function generate(RegionScore $score): array
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('AI summary is not configured.');
        }

        $score->loadMissing(['region', 'index']);

        $body = $this->client->message(
            system: self::BASE_RULES,
            user: $this->structuredInput($score),
            maxTokens: 220,
        );

        return ['body' => $body, 'model' => $this->client->model()];
    }

    private function structuredInput(RegionScore $score): string
    {
        $lines = [
            "Region: {$score->region->name}, {$score->region->state}",
            "Index: {$score->index->name}",
            "Period: {$score->period_start->toDateString()} to {$score->period_end->toDateString()}",
            'Score: '.($score->score ?? 'no data'),
            'Risk band: '.RiskBand::forScore($score->score),
            'Signal breakdown:',
        ];

        foreach ($score->breakdown ?? [] as $signal) {
            if (($signal['status'] ?? null) === 'no_data') {
                $lines[] = "- {$signal['signal_type_code']}: no data available this period";

                continue;
            }

            $lines[] = "- {$signal['signal_type_code']}: raw value {$signal['raw_value']} {$signal['unit']}, ".
                "normalized {$signal['normalized_score']}, weight {$signal['weight']}, ".
                "contribution to final score {$signal['contribution_to_final_score']}";
        }

        return implode("\n", $lines);
    }
}
