<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Models\ScoringIndex;
use App\Services\Scoring\TrainedModelScoringStrategy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Exposes what was previously tinker/DB-only: regions.preferred_scoring_strategy (see
 * ScoringStrategyResolver and TrainedModelScoringStrategy's docblock for the full mechanism).
 *
 * Deliberately honest in the view about what this toggle actually does today: predict() is
 * unimplemented, so even a region set to 'trained_model' keeps getting formula-scored — this
 * page shows that plainly (which indices have a model file at all) rather than implying a
 * working feature that isn't there yet.
 */
class ScoringStrategyController extends Controller
{
    public function index(): View
    {
        $indices = ScoringIndex::query()->orderBy('name')->get();
        $trainedModel = app(TrainedModelScoringStrategy::class);

        // isAvailable() takes a Region argument but never actually reads it — the model file
        // is keyed by index code only — so an unsaved instance is fine here.
        $modelAvailability = $indices->mapWithKeys(fn (ScoringIndex $index) => [
            $index->code => $trainedModel->isAvailable(new Region, $index),
        ]);

        $regions = Region::query()->active()->orderBy('name')->get();

        return view('admin.scoring-strategy.index', [
            'regions' => $regions,
            'modelAvailability' => $modelAvailability,
            'anyModelAvailable' => $modelAvailability->contains(true),
        ]);
    }

    public function update(Request $request, Region $region): RedirectResponse
    {
        $validated = $request->validate([
            'preferred_scoring_strategy' => ['nullable', 'in:formula,trained_model'],
        ]);

        $region->update(['preferred_scoring_strategy' => $validated['preferred_scoring_strategy'] ?? null]);

        return back()->with('status', "{$region->name}'s scoring strategy preference saved.");
    }
}
