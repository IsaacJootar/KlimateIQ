<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IndexActionRecommendation;
use App\Models\ScoringIndex;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Edits the plain-English "what to do about it" text shown per index + risk band —
 * rule-based, not AI. Every index has exactly one row per band (green/amber/red).
 */
class ActionRecommendationController extends Controller
{
    private const BANDS = ['green', 'amber', 'red'];

    public function index(Request $request): View
    {
        $indices = ScoringIndex::query()->orderBy('name')->get();
        $index = $indices->firstWhere('index_id', $request->query('index')) ?? $indices->first();

        $actions = IndexActionRecommendation::query()
            ->where('index_id', $index->index_id)
            ->get()
            ->keyBy('risk_band');

        return view('admin.actions.index', [
            'indices' => $indices,
            'index' => $index,
            'bands' => self::BANDS,
            'actions' => $actions,
        ]);
    }

    public function update(Request $request, ScoringIndex $index): RedirectResponse
    {
        $validated = $request->validate([
            'action_text' => ['required', 'array'],
            'action_text.*' => ['required', 'string', 'max:1000'],
        ]);

        foreach (self::BANDS as $band) {
            IndexActionRecommendation::query()->updateOrCreate(
                ['index_id' => $index->index_id, 'risk_band' => $band],
                ['action_text' => $validated['action_text'][$band]]
            );
        }

        return back()->with('status', "{$index->name} recommended actions saved.");
    }
}
