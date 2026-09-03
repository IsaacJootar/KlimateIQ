<?php

namespace App\Support;

use App\Models\Region;
use App\Models\RegionScoringConfig;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;

/**
 * Reduces an index's per-signal calibration statuses to one plain-language line for the reader
 * of a score — so the "how trustworthy is this" caveat from docs/MODEL.md is visible in the
 * product, not just the docs. Cached per request.
 */
class IndexCalibration
{
    /**
     * The lowest calibration rank across the index's enabled weights and the bounds those
     * signals actually use — the score is only as calibrated as its weakest input.
     */
    public static function weakestStatus(ScoringIndex $index): CalibrationStatus
    {
        return once(function () use ($index) {
            $weights = RegionScoringConfig::query()
                ->where('index_id', $index->index_id)
                ->whereNull('region_id')
                ->where('enabled', true)
                ->where('weight', '>', 0)
                ->with('signalType')
                ->get();

            if ($weights->isEmpty()) {
                return CalibrationStatus::Placeholder;
            }

            $signalCodes = $weights->pluck('signalType.code')->filter();

            $boundStatuses = ScoringCalibrationParameter::query()
                ->where('index_id', $index->index_id)
                ->whereNull('region_id')
                ->get()
                ->filter(fn ($p) => $signalCodes->contains(str($p->parameter_key)->beforeLast('_')->value()))
                ->pluck('calibration_status');

            // A lone weight (a single-signal index) has nothing to calibrate — judge it on its
            // bound alone. With two or more signals the relative weights are a real choice.
            $weightStatuses = $weights->count() > 1 ? $weights->pluck('calibration_status') : collect();

            $all = $weightStatuses->concat($boundStatuses)->filter();

            return $all->isEmpty()
                ? CalibrationStatus::Placeholder
                : $all->sortBy(fn (CalibrationStatus $s) => $s->rank())->first();
        });
    }

    /**
     * Does this region have its own (not the system-wide) calibration bound for a signal, from a
     * real source rather than an uncalibrated placeholder? Used to guard the Riverine Flood
     * Forecast: a reach with no reach-specific flood threshold is shown as "calibration pending",
     * not scored against a borrowed number (BUILD_PLAN.md T4/T5 follow-up).
     */
    public static function hasRegionBound(ScoringIndex $index, Region $region, string $signalCode, string $suffix = 'MAX'): bool
    {
        return ScoringCalibrationParameter::query()
            ->where('index_id', $index->index_id)
            ->where('region_id', $region->region_id)
            ->where('parameter_key', "{$signalCode}_{$suffix}")
            ->whereNotIn('calibration_status', [CalibrationStatus::Placeholder->value])
            ->exists();
    }

    /**
     * A one-sentence caveat for the score's footnote, or null once every input is
     * outcome-validated (no index is there yet).
     */
    public static function note(ScoringIndex $index): ?string
    {
        return match (self::weakestStatus($index)) {
            CalibrationStatus::Placeholder => 'Not yet calibrated against real outcomes — treat this as a prioritisation aid, not a forecast.',
            CalibrationStatus::AdminTuned => 'Ranges and weights set by hand, not validated against outcome data yet — a prioritisation aid.',
            CalibrationStatus::Reference, CalibrationStatus::ReferenceDerived => 'Ranges come from real data / published references; the score is a prioritisation signal, not validated against outcomes.',
            CalibrationStatus::OutcomeValidated => null,
        };
    }
}
