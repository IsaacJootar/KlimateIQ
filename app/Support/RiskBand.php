<?php

namespace App\Support;

/**
 * The single 34/67 cut used everywhere a score is turned into green/amber/red —
 * region cards, the dashboard's high-risk count, and action recommendations all
 * need to agree on the same bands.
 */
class RiskBand
{
    public static function forScore(?float $score): string
    {
        return match (true) {
            $score === null => 'none',
            $score < 34 => 'green',
            $score < 67 => 'amber',
            default => 'red',
        };
    }
}
