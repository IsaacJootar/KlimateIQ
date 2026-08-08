<?php

namespace App\Services\Ai;

use App\Models\RegionScore;
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
    You write short summaries of a climate-health risk score for a Nigerian LGA (local
    government area). You are given the region's name, the named index it belongs to, its
    current score (0-100), and a per-signal breakdown as structured data. Your job is only to
    turn that structure into clear, plain prose for a health officer or emergency response
    coordinator deciding what to do next.

    Hard rules, without exception:
    - Never introduce a signal, value, weight, or contribution that is not present in the data
      you were given. If it is not in the data, it does not exist.
    - Do not predict the future.
    - Do not give clinical or medical treatment advice.
    - Every claim you make must trace to an item in the data.
    - Plain language, no jargon, 2-4 short sentences.
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
            'Signal breakdown:',
        ];

        foreach ($score->breakdown ?? [] as $signal) {
            if (($signal['status'] ?? null) === 'no_data') {
                $lines[] = "- {$signal['signal_type_code']}: no data available this period";

                continue;
            }

            $lines[] = "- {$signal['signal_type_code']}: raw value {$signal['raw_value']} {$signal['unit']}, ".
                "normalized {$signal['normalized_score']}, weight {$signal['weight']}, contribution {$signal['contribution']}";
        }

        return implode("\n", $lines);
    }
}
