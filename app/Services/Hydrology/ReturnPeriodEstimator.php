<?php

namespace App\Services\Hydrology;

use Illuminate\Support\Carbon;

/**
 * Empirical flood return levels from a daily-discharge record, the standard first-pass method:
 * take the highest flow of each year (the annual-maximum series), then read off the flow for a
 * given return period with the Weibull plotting position. No distribution fitting, no external
 * thresholds — just "a flow this reach reaches about once every N years, over the record we have".
 *
 * A 2-year return level is roughly the median annual flood; 20-year is a rare one. This is a
 * genuine hydrological quantity (unlike "observed max × 1.4"), but it is only as good as the
 * record length — GloFAS reanalysis back to the mid-1980s gives ~40 years, which is enough for
 * a 2-to-20-year band, not a 100-year one.
 */
class ReturnPeriodEstimator
{
    /**
     * The largest value in each calendar year that has at least $minDaysPerYear readings.
     *
     * @param  array<string, float>  $dailySeries  date (Y-m-d) => discharge
     * @return array<int, float> year => annual maximum
     */
    public function annualMaxima(array $dailySeries, int $minDaysPerYear = 300): array
    {
        $byYear = [];
        foreach ($dailySeries as $date => $value) {
            $byYear[Carbon::parse($date)->year][] = $value;
        }

        $maxima = [];
        foreach ($byYear as $year => $values) {
            if (count($values) >= $minDaysPerYear) {
                $maxima[$year] = max($values);
            }
        }

        return $maxima;
    }

    /**
     * Return levels for each requested return period, in the same discharge unit as the input.
     *
     * @param  array<int, float>  $annualMaxima
     * @param  array<int|float>  $returnPeriods  e.g. [2, 5, 20]
     * @return array<string, float> "2" => flow, "5" => flow, …
     */
    public function returnLevels(array $annualMaxima, array $returnPeriods): array
    {
        $sorted = array_values($annualMaxima);
        sort($sorted);
        $n = count($sorted);

        if ($n === 0) {
            return [];
        }

        $out = [];
        foreach ($returnPeriods as $t) {
            // Weibull: rank r (1..n) has non-exceedance probability p = r / (n + 1).
            // The level for return period T sits at p = 1 - 1/T, i.e. fractional rank p·(n+1).
            $rank = (1 - 1 / $t) * ($n + 1);
            $out[(string) $t] = $this->atFractionalRank($sorted, $rank);
        }

        return $out;
    }

    /**
     * A low-flow reference — the value the daily record sits at or above ~90% of the time.
     *
     * @param  array<string, float>  $dailySeries
     */
    public function lowFlow(array $dailySeries, float $percentile = 0.10): float
    {
        $sorted = array_values($dailySeries);
        sort($sorted);
        $n = count($sorted);

        return $n === 0 ? 0.0 : $this->atFractionalRank($sorted, $percentile * ($n + 1));
    }

    /**
     * @param  array<int, float>  $sortedAscending
     */
    private function atFractionalRank(array $sortedAscending, float $rank): float
    {
        $n = count($sortedAscending);

        if ($rank <= 1) {
            return $sortedAscending[0];
        }
        if ($rank >= $n) {
            return $sortedAscending[$n - 1];
        }

        $lower = (int) floor($rank);
        $frac = $rank - $lower;

        return $sortedAscending[$lower - 1] + $frac * ($sortedAscending[$lower] - $sortedAscending[$lower - 1]);
    }
}
