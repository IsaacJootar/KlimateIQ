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
        return view('saved-views.index', [
            'savedViews' => SavedView::query()->where('user_id', Auth::id())->with('index')->orderByDesc('created_at')->get(),
            'regions' => Region::query()->orderBy('name')->get(),
            'indices' => ScoringIndex::all(),
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

        SavedView::query()->create([
            'user_id' => Auth::id(),
            'name' => $validated['name'],
            'index_id' => $validated['index_id'] ?? null,
            'region_ids' => $validated['region_ids'],
        ]);

        return back()->with('status', 'View saved.');
    }

    public function destroy(SavedView $savedView): RedirectResponse
    {
        abort_unless($savedView->user_id === Auth::id(), 403);
        $savedView->delete();

        return back()->with('status', 'Saved view deleted.');
    }
}
