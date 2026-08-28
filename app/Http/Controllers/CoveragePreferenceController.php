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
 * The "Workspace" page — a user picks the sectors that match their job, refines the indices
 * under them, and (optionally) narrows the regions they watch. An empty selection means "see
 * everything" (the default for a new user); once a user picks specifics, other pages scope to
 * just those. Always reconfigurable — this never locks a user out of anything.
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
            'regions' => Region::query()->orderBy('name')->get(),
            'indices' => ScoringIndex::query()->orderBy('name')->get(),
            'subscribedSectorIds' => $user->sectorSubscriptions()->pluck('sector_id')->all(),
            'subscribedRegionIds' => $user->regionSubscriptions()->pluck('region_id')->all(),
            'subscribedIndexIds' => $user->indexSubscriptions()->pluck('index_id')->all(),
        ]);
    }

    public function update(Request $request, WriteCoverage $writeCoverage): RedirectResponse
    {
        $validated = $request->validate([
            'sector_ids' => ['nullable', 'array'],
            'sector_ids.*' => ['integer', 'exists:sectors,sector_id'],
            'index_scope' => ['required', 'in:all,specific'],
            'index_ids' => ['nullable', 'array'],
            'index_ids.*' => ['exists:indices,index_id'],
            'region_scope' => ['required', 'in:all,specific'],
            'region_ids' => ['nullable', 'array'],
            'region_ids.*' => ['exists:regions,region_id'],
        ]);

        $sectorIds = $validated['sector_ids'] ?? [];

        // "All indices" = no filter. "Only the ones I pick" uses the ticked boxes, or — if a
        // sector was picked but nothing was refined — every index in those sectors.
        $indexIds = $validated['index_scope'] === 'all'
            ? []
            : (($validated['index_ids'] ?? []) ?: WriteCoverage::indicesForSectors($sectorIds));

        $writeCoverage(
            Auth::user(),
            $sectorIds,
            $indexIds,
            $validated['region_scope'],
            $validated['region_ids'] ?? [],
        );

        return back()->with('status', 'Workspace updated.');
    }
}
