<?php

namespace App\Services\Scoring;

use App\Models\Region;
use App\Models\ScoringIndex;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Drop-in replacement for WeightedFormulaScoringStrategy once a real model has been trained —
 * same interface, same input/output shape, so activating it means flipping a config flag, not
 * changing the scoring engine, the alerts layer, or the dashboard.
 *
 * Not trained yet. isAvailable() returns false until a model artifact is present, so
 * RegionScoringService falls back to the formula strategy automatically — this class is the
 * seam, not a promise.
 *
 * To activate:
 *   1. Train against historical case data (Malaria Atlas Project, DHS/MIS) matched to the same
 *      region_id + period_start/period_end grain as region_signals.
 *   2. Export the trained model to storage/app/models/{index_code}.json (or swap the loader
 *      below for your framework's native format).
 *   3. Set SCORING_STRATEGY=trained_model in .env (or region_scoring_configs' preferred
 *      strategy override), or set it per-region via regions.preferred_scoring_strategy.
 *   4. Implement predict() below to load the artifact and score region_signals for the period.
 *
 * See docs/INGESTION_GUIDE.md for the exact historical-data format expected.
 */
class TrainedModelScoringStrategy implements ScoringStrategy
{
    public function code(): string
    {
        return 'trained_model';
    }

    public function isAvailable(Region $region, ScoringIndex $index): bool
    {
        return is_file($this->modelPath($index));
    }

    public function score(ScoringIndex $index, Region $region, Carbon $periodStart, Carbon $periodEnd): ScoreResult
    {
        if (! $this->isAvailable($region, $index)) {
            throw new RuntimeException(
                "No trained model found for index '{$index->code}' at {$this->modelPath($index)}. ".
                'RegionScoringService should have fallen back to the formula strategy — this call was made directly.'
            );
        }

        return $this->predict($index, $region, $periodStart, $periodEnd);
    }

    private function predict(ScoringIndex $index, Region $region, Carbon $periodStart, Carbon $periodEnd): ScoreResult
    {
        // Intentionally unimplemented until a model is trained — see class docblock.
        throw new RuntimeException('TrainedModelScoringStrategy::predict() is not yet implemented.');
    }

    private function modelPath(ScoringIndex $index): string
    {
        return storage_path("app/models/{$index->code}.json");
    }
}
