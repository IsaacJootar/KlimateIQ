<?php

namespace App\Http\Controllers;

use App\Actions\WriteCoverage;
use App\Models\Region;
use App\Models\ScoringIndex;
use App\Models\Sector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The "Workspace" page. Sectors are the primary control: a user picks the sectors they monitor
 * and their dashboard + alerts scope to every risk index in those sectors (see
 * App\Support\IndexCoverage). Optionally they can hide a few of those indices — a refinement
 * that only ever narrows within the sector set. Regions are an independent, optional narrowing.
 * An empty selection means "see everything". Always reconfigurable.
 *
 * All persistence goes through App\Actions\WriteCoverage, shared with the onboarding wizard.
 */
class CoveragePreferenceController extends Controller
{
    public function edit(): View
    {
        $user = Auth::user();

        return view('coverage.edit', [
            'sectors' => Sector::query()->with('indices')->orderBy('sort_order')->get(),
            'indices' => ScoringIndex::query()->orderBy('name')->get(),
            'regions' => Region::query()->orderBy('name')->get(),
            'subscribedSectorIds' => $user->sectorSubscriptions()->pluck('sector_id')->all(),
            'subscribedRegionIds' => $user->regionSubscriptions()->pluck('region_id')->all(),
            // The stored refinement, if any — an explicit "keep only these" within the sectors.
            'refinedIndexIds' => $user->indexSubscriptions()->pluck('index_id')->all(),
        ]);
    }

    public function update(Request $request, WriteCoverage $writeCoverage): RedirectResponse
    {
        $validated = $request->validate([
            'sector_ids' => ['nullable', 'array'],
            'sector_ids.*' => ['integer', 'exists:sectors,sector_id'],
            'index_ids' => ['nullable', 'array'],
            'index_ids.*' => ['exists:indices,index_id'],
            'region_scope' => ['required', 'in:all,specific'],
            'region_ids' => ['nullable', 'array'],
            'region_ids.*' => ['exists:regions,region_id'],
        ]);

        $sectorIds = $validated['sector_ids'] ?? [];

        $writeCoverage(
            Auth::user(),
            $sectorIds,
            // The ticked index boxes are the "keep" set; refinementFor() stores nothing unless
            // it's a genuine narrowing within the sectors.
            WriteCoverage::refinementFor($sectorIds, $validated['index_ids'] ?? []),
            $validated['region_scope'],
            $validated['region_ids'] ?? [],
        );

        return back()->with('status', 'Workspace updated.');
    }
}
