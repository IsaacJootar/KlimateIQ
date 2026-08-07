<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\SavedView;
use App\Models\ScoringIndex;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SavedViewController extends Controller
{
    public function index(): View
    {
        $agencyId = Auth::user()->agency_id;

        return view('saved-views.index', [
            'savedViews' => SavedView::query()->where('user_id', Auth::id())->with('index')->orderByDesc('created_at')->get(),
            'sharedViews' => $agencyId
                ? SavedView::query()->where('agency_id', $agencyId)->where('user_id', '!=', Auth::id())->with(['index', 'user'])->orderByDesc('created_at')->get()
                : collect(),
            'regions' => Region::query()->orderBy('name')->get(),
            'indices' => ScoringIndex::all(),
            'hasAgency' => $agencyId !== null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'index_id' => ['nullable', 'exists:indices,index_id'],
            'region_ids' => ['required', 'array', 'min:1'],
            'region_ids.*' => ['exists:regions,region_id'],
        ]);

        $shareWithAgency = $request->boolean('share_with_agency') && Auth::user()->agency_id !== null;

        SavedView::query()->create([
            'user_id' => Auth::id(),
            'agency_id' => $shareWithAgency ? Auth::user()->agency_id : null,
            'name' => $validated['name'],
            'index_id' => $validated['index_id'] ?? null,
            'region_ids' => $validated['region_ids'],
        ]);

        return back()->with('status', $shareWithAgency ? 'View saved and shared with your agency.' : 'View saved.');
    }

    public function destroy(SavedView $savedView): RedirectResponse
    {
        abort_unless($savedView->user_id === Auth::id(), 403);
        $savedView->delete();

        return back()->with('status', 'Saved view deleted.');
    }
}
