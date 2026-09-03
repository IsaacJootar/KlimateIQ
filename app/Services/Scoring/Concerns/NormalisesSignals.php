<?php

namespace App\Services\Scoring\Concerns;

use App\Models\Region;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;

/**
 * The 0–100 normalisation shared by the observed and forecast formula strategies: a signal
 * value mapped against its calibrated min/max bounds, in whichever direction the config says.
 * Extracted verbatim from WeightedFormulaScoringStrategy so the forecast strategy scores a
 * discharge value exactly the way the observed engine would.
 */
trait NormalisesSignals
{
    protected function normalize(float $value, float $min, float $max, bool $higherIsWorse): float
    {
        if ($max <= $min) {
            return 0.0;
        }

        $ratio = ($value - $min) / ($max - $min);
        $ratio = min(1.0, max(0.0, $ratio));

        return ($higherIsWorse ? $ratio : 1 - $ratio) * 100;
    }

    /**
     * $reach (T4/T5 follow-up) selects a per-reach bound for a multi-river LGA — the Niger and
     * the Benue at Lokoja have different flood levels. Resolution order: this reach's own bound,
     * then the LGA-wide (reach = null) bound, then the system-wide, then a hard default.
     *
     * @return array{0: float, 1: float}
     */
    protected function calibrationBounds(ScoringIndex $index, Region $region, string $signalCode, ?string $reach = null): array
    {
        $lookup = fn (?int $regionId, ?string $reachSlug, string $suffix) => ScoringCalibrationParameter::query()
            ->where('index_id', $index->index_id)
            ->where('region_id', $regionId)
            ->where(fn ($q) => $reachSlug === null ? $q->whereNull('reach') : $q->where('reach', $reachSlug))
            ->where('parameter_key', "{$signalCode}_{$suffix}")
            ->value('parameter_value');

        $resolve = fn (string $suffix, float $default) => ($reach !== null ? $lookup($region->region_id, $reach, $suffix) : null)
            ?? $lookup($region->region_id, null, $suffix)
            ?? $lookup(null, null, $suffix)
            ?? $default;

        return [(float) $resolve('MIN', 0.0), (float) $resolve('MAX', 100.0)];
    }
}
