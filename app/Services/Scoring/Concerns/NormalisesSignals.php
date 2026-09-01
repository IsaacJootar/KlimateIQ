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
     * @return array{0: float, 1: float}
     */
    protected function calibrationBounds(ScoringIndex $index, Region $region, string $signalCode): array
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
}
