<?php

namespace App\Services\Scoring;

use App\Models\Region;
use App\Models\ScoringIndex;

class ScoringStrategyResolver
{
    public function __construct(
        private readonly WeightedFormulaScoringStrategy $formula,
        private readonly TrainedModelScoringStrategy $trainedModel,
    ) {}

    public function resolve(Region $region, ScoringIndex $index): ScoringStrategy
    {
        $preferred = $region->preferred_scoring_strategy ?? config('scoring.strategy', 'formula');

        if ($preferred === 'trained_model' && $this->trainedModel->isAvailable($region, $index)) {
            return $this->trainedModel;
        }

        return $this->formula;
    }
}
