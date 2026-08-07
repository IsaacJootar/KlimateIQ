<?php

namespace App\Support;

/**
 * Turns two scores into a plain-English direction sentence — "Up 15 points over
 * the last 2 weeks" — so an officer can judge urgency, not just severity, without
 * having to read a chart.
 */
class TrendSummary
{
    /**
     * @return array{direction: string, label: string}
     */
    public static function describe(?float $latest, ?float $prior): array
    {
        if ($latest === null) {
            return ['direction' => 'unknown', 'label' => 'No score yet'];
        }

        if ($prior === null) {
            return ['direction' => 'unknown', 'label' => 'Not enough history yet to show a trend'];
        }

        $delta = round($latest - $prior, 1);

        if (abs($delta) < 0.05) {
            return ['direction' => 'flat', 'label' => 'No change over the last 2 weeks'];
        }

        $verb = $delta > 0 ? 'Up' : 'Down';
        $points = rtrim(rtrim(number_format(abs($delta), 1), '0'), '.');

        return [
            'direction' => $delta > 0 ? 'up' : 'down',
            'label' => "{$verb} {$points} points over the last 2 weeks",
        ];
    }
}
