<?php

namespace App\Services\Scoring;

use App\Models\Region;
use App\Models\RegionScoringConfig;
use App\Models\RegionSignal;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use Illuminate\Support\Carbon;

/**
 * Transparent, configurable, calibratable — every score traces back to exactly which signal
 * drove it. This is what gets demoed: no training step, no black box, live today.
 *
 * Each enabled signal for the index is normalized to 0-100 against calibrated min/max bounds
 * (scoring_calibration_parameters), optionally lifted by a region's vulnerability profile, then
 * combined by its configured weight (region_scoring_configs). Missing signals are skipped and
 * the remaining weights are renormalized, rather than treating "no data yet" as "zero risk".
 */
class WeightedFormulaScoringStrategy implements ScoringStrategy
{
    public function code(): string
    {
        return 'formula';
    }

    public function isAvailable(Region $region, ScoringIndex $index): bool
    {
        return true;
    }

    public function score(ScoringIndex $index, Region $region, Carbon $periodStart, Carbon $periodEnd): ScoreResult
    {
        $configs = RegionScoringConfig::query()
            ->with('signalType')
            ->where('index_id', $index->index_id)
            ->where('enabled', true)
            ->where(fn ($q) => $q->where('region_id', $region->region_id)->orWhereNull('region_id'))
            ->get()
            // A region-specific config overrides the system-wide default for the same signal.
            ->groupBy('signal_type_id')
            ->map(fn ($group) => $group->sortByDesc(fn ($c) => $c->region_id !== null)->first());

        $vulnerabilityFactor = $this->vulnerabilityFactor($region);
        $breakdown = [];
        $weightedSum = 0.0;
        $totalWeight = 0.0;

        foreach ($configs as $config) {
            $signalType = $config->signalType;

            $signal = RegionSignal::query()
                ->where('region_id', $region->region_id)
                ->where('signal_type_id', $signalType->signal_type_id)
                ->where('period_start', $periodStart->toDateString())
                ->where('period_end', $periodEnd->toDateString())
                ->first();

            if ($signal === null) {
                $breakdown[] = [
                    'signal_type_code' => $signalType->code,
                    'status' => 'no_data',
                    'weight' => (float) $config->weight,
                ];

                continue;
            }

            [$min, $max] = $this->calibrationBounds($index, $region, $signalType->code);
            $higherIsWorse = $config->higher_is_worse ?? $signalType->higher_is_worse;
            $normalized = $this->normalize((float) $signal->value, $min, $max, $higherIsWorse);

            $multiplier = $config->vulnerability_weight !== null
                ? 1 + ($vulnerabilityFactor * (float) $config->vulnerability_weight)
                : 1.0;

            $contribution = min(100, $normalized * $multiplier);
            $weight = (float) $config->weight;

            $weightedSum += $contribution * $weight;
            $totalWeight += $weight;

            $breakdown[] = [
                'signal_type_code' => $signalType->code,
                'raw_value' => (float) $signal->value,
                'unit' => $signalType->unit,
                'normalized_score' => round($normalized, 2),
                'vulnerability_multiplier' => round($multiplier, 4),
                'weight' => $weight,
                'contribution' => round($contribution, 2),
                // Placeholder — filled in below once totalWeight is known. This is the number
                // that should actually sum, across every row, to the final score: each signal's
                // true share of it, not just its pre-weight normalized value.
                'contribution_to_final_score' => null,
            ];
        }

        if ($totalWeight <= 0.0) {
            return new ScoreResult(score: null, breakdown: $breakdown, scoringVersion: 'formula-v1');
        }

        $score = round(min(100, max(0, $weightedSum / $totalWeight)), 2);

        $breakdown = array_map(function (array $row) use ($totalWeight) {
            if (($row['status'] ?? null) === 'no_data') {
                return $row;
            }

            $row['contribution_to_final_score'] = round(($row['contribution'] * $row['weight']) / $totalWeight, 2);

            return $row;
        }, $breakdown);

        return new ScoreResult(score: $score, breakdown: $breakdown, scoringVersion: 'formula-v1');
    }

    private function normalize(float $value, float $min, float $max, bool $higherIsWorse): float
    {
        if ($max <= $min) {
            return 0.0;
        }

        $ratio = ($value - $min) / ($max - $min);
        $ratio = min(1.0, max(0.0, $ratio));

        return ($higherIsWorse ? $ratio : 1 - $ratio) * 100;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function calibrationBounds(ScoringIndex $index, Region $region, string $signalCode): array
    {
        $lookup = fn (?int $regionId, string $suffix) => ScoringCalibrationParameter::query()
            ->where('index_id', $index->index_id)
            ->where('region_id', $regionId)
            ->where('parameter_key', "{$signalCode}_{$suffix}")
            ->value('parameter_value');

        $min = $lookup($region->region_id, 'MIN') ?? $lookup(null, 'MIN') ?? 0.0;
        $max = $lookup($region->region_id, 'MAX') ?? $lookup(null, 'MAX') ?? 100.0;

        return [(float) $min, (float) $max];
    }

    /**
     * A 0-1 composite of how vulnerable this region's population is, from its vulnerability
     * profile. Regions with no profile yet are treated as average (0.5) rather than zero, so
     * missing demographic data doesn't silently suppress the vulnerability weighting.
     */
    private function vulnerabilityFactor(Region $region): float
    {
        $profile = $region->vulnerabilityProfile;

        if ($profile === null) {
            return 0.5;
        }

        $components = array_filter([
            $profile->pct_children !== null ? (float) $profile->pct_children / 100 : null,
            $profile->pct_elderly !== null ? (float) $profile->pct_elderly / 100 : null,
            $profile->pct_outdoor_labor !== null ? (float) $profile->pct_outdoor_labor / 100 : null,
        ], fn ($v) => $v !== null);

        if ($components === []) {
            return 0.5;
        }

        return min(1.0, max(0.0, array_sum($components) / count($components)));
    }
}
