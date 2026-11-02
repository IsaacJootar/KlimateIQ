<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\ScoringIndex;
use App\Support\AgencyIndexCoverage;
use App\Support\PortfolioType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AgencyManagementController extends Controller
{
    public function index(): View
    {
        $agencies = Agency::query()->withCount('users')->with('indexOverrides')->orderBy('name')->get();

        // Precomputed here, not resolved inline in the view, so the "which indices does this
        // agency currently see" logic lives in one place (App\Support\AgencyIndexCoverage)
        // rather than being re-derived in Blade.
        $visibleIndexIds = $agencies->mapWithKeys(
            fn (Agency $agency) => [$agency->agency_id => AgencyIndexCoverage::resolve($agency)->pluck('index_id')]
        );

        return view('admin.agencies.index', [
            'agencies' => $agencies,
            'portfolioTypes' => PortfolioType::cases(),
            'indices' => ScoringIndex::query()->orderBy('name')->get(),
            'visibleIndexIds' => $visibleIndexIds,
        ]);
    }

    public function update(Request $request, Agency $agency): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'portfolio_type' => ['nullable', Rule::enum(PortfolioType::class)],
        ]);

        $agency->update($validated);

        return back()->with('status', "Updated \"{$agency->name}\".");
    }

    /**
     * Grants or revokes the Platform Overview page (a cross-agency view, not scoped to any
     * single agency's own coverage) for every user under this agency. Rides on the agency
     * rather than a per-user flag — see User::hasFederalOversight().
     */
    public function toggleOversight(Agency $agency): RedirectResponse
    {
        $agency->update(['federal_oversight' => ! $agency->federal_oversight]);

        $state = $agency->federal_oversight ? 'granted' : 'revoked';

        return back()->with('status', "Platform Overview access {$state} for \"{$agency->name}\".");
    }

    /**
     * Folds one agency into another: every user, saved view, report, threshold, and
     * index-visibility override pointed at the duplicate gets repointed at the one being kept,
     * then the duplicate is deleted. Reassigning first matters — threshold_configs.agency_id and
     * agency_index_overrides.agency_id both cascade-delete on agency deletion (unlike
     * users/saved_views/report_requests, which just null out), so deleting the duplicate without
     * reassigning first could silently destroy someone's thresholds or a Module 1 index
     * override. agency_index_overrides specifically needs a skip-on-conflict reassign (not a
     * blind UPDATE) since both agencies could already have an override row for the same index —
     * the unique (agency_id, index_id) constraint would reject the merge otherwise.
     */
    public function merge(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'keep_agency_id' => ['required', 'uuid', 'exists:agencies,agency_id'],
            'duplicate_agency_id' => ['required', 'uuid', 'exists:agencies,agency_id', 'different:keep_agency_id'],
        ]);

        $keep = Agency::query()->findOrFail($validated['keep_agency_id']);
        $duplicate = Agency::query()->findOrFail($validated['duplicate_agency_id']);

        DB::transaction(function () use ($keep, $duplicate) {
            foreach (['users', 'saved_views', 'report_requests', 'threshold_configs'] as $table) {
                DB::table($table)
                    ->where('agency_id', $duplicate->agency_id)
                    ->update(['agency_id' => $keep->agency_id]);
            }

            $keptIndexIds = DB::table('agency_index_overrides')
                ->where('agency_id', $keep->agency_id)
                ->pluck('index_id');

            DB::table('agency_index_overrides')
                ->where('agency_id', $duplicate->agency_id)
                ->whereNotIn('index_id', $keptIndexIds)
                ->update(['agency_id' => $keep->agency_id]);

            DB::table('agency_index_overrides')
                ->where('agency_id', $duplicate->agency_id)
                ->delete();

            $duplicate->delete();
        });

        return back()->with('status', "Merged \"{$duplicate->name}\" into \"{$keep->name}\".");
    }

    /**
     * Admin override of one index's visibility for one agency — see
     * App\Support\AgencyIndexCoverage. Toggling back to exactly what the portfolio-type default
     * would already show removes the override row entirely rather than storing a redundant one,
     * matching the "only store what's different" shape the whole system is built on.
     */
    public function toggleIndexOverride(Request $request, Agency $agency): RedirectResponse
    {
        $validated = $request->validate([
            'index_id' => ['required', 'integer', 'exists:indices,index_id'],
        ]);

        $index = ScoringIndex::query()->findOrFail($validated['index_id']);
        $defaultIncludes = in_array($index->code, $agency->portfolio_type?->defaultIndexCodes() ?? [], true);

        $existing = $agency->indexOverrides()->where('index_id', $index->index_id)->first();
        $currentlyVisible = $existing?->enabled ?? $defaultIncludes;
        $wantEnabled = ! $currentlyVisible;

        if ($wantEnabled === $defaultIncludes) {
            $existing?->delete();
        } else {
            $agency->indexOverrides()->updateOrCreate(
                ['index_id' => $index->index_id],
                ['enabled' => $wantEnabled],
            );
        }

        return back()->with('status', "{$index->name} ".($wantEnabled ? 'enabled' : 'disabled')." for \"{$agency->name}\".");
    }
}
