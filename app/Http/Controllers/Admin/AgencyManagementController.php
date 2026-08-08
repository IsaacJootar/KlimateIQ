<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AgencyManagementController extends Controller
{
    public function index(): View
    {
        return view('admin.agencies.index', [
            'agencies' => Agency::query()->withCount('users')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Agency $agency): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
        ]);

        $agency->update($validated);

        return back()->with('status', "Renamed to \"{$agency->name}\".");
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
     * Folds one agency into another: every user, saved view, report, and threshold pointed
     * at the duplicate gets repointed at the one being kept, then the duplicate is deleted.
     * Reassigning first matters — threshold_configs.agency_id cascade-deletes on agency
     * deletion (unlike users/saved_views/report_requests, which just null out), so deleting
     * the duplicate without reassigning first could silently destroy someone's thresholds.
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

            $duplicate->delete();
        });

        return back()->with('status', "Merged \"{$duplicate->name}\" into \"{$keep->name}\".");
    }
}
