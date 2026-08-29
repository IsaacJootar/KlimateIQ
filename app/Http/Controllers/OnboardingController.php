<?php

namespace App\Http\Controllers;

use App\Actions\WriteCoverage;
use App\Models\Region;
use App\Models\Sector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The first-run setup wizard: pick the sectors that match your work, refine the indices under
 * them, then optionally narrow the regions you watch. Persistence goes through the same
 * App\Actions\WriteCoverage the Workspace page uses. Finishing (or skipping) stamps
 * users.onboarded_at, which is what App\Http\Middleware\EnsureOnboarded checks.
 */
class OnboardingController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();

        if ($user->onboarded_at !== null) {
            return redirect()->route('dashboard');
        }

        return view('onboarding.index', [
            'sectors' => Sector::query()->with('indices')->orderBy('sort_order')->get(),
            'userState' => $user->state,
            'regions' => Region::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, WriteCoverage $writeCoverage): RedirectResponse
    {
        $validated = $request->validate([
            'sector_ids' => ['nullable', 'array'],
            'sector_ids.*' => ['integer', 'exists:sectors,sector_id'],
            'index_ids' => ['nullable', 'array'],
            'index_ids.*' => ['exists:indices,index_id'],
            'region_scope' => ['required', 'in:all,state,specific'],
            'region_ids' => ['nullable', 'array'],
            'region_ids.*' => ['exists:regions,region_id'],
        ]);

        $sectorIds = $validated['sector_ids'] ?? [];

        // Step 2's boxes are the "keep" set. If the user left every index of their sectors
        // ticked, nothing is stored and the sectors drive coverage; only a real narrowing is
        // persisted.
        $indexIds = WriteCoverage::refinementFor($sectorIds, $validated['index_ids'] ?? []);

        [$regionScope, $regionIds] = match ($validated['region_scope']) {
            'state' => ['specific', $this->regionIdsForState(Auth::user()->state)],
            'specific' => ['specific', $validated['region_ids'] ?? []],
            default => ['all', []],
        };

        $writeCoverage(Auth::user(), $sectorIds, $indexIds, $regionScope, $regionIds);

        $this->markOnboarded();

        return redirect()->route('dashboard')->with('status', "You're all set — here's your workspace.");
    }

    public function skip(): RedirectResponse
    {
        $this->markOnboarded();

        return redirect()->route('dashboard');
    }

    private function markOnboarded(): void
    {
        $user = Auth::user();
        $user->onboarded_at = now();
        $user->save();
    }

    /**
     * @return array<int>
     */
    private function regionIdsForState(?string $userState): array
    {
        // Registration stores "FCT (Abuja)"; the seeded regions use "FCT".
        $state = $userState !== null && str_starts_with($userState, 'FCT') ? 'FCT' : (string) $userState;

        return Region::query()->where('state', $state)->pluck('region_id')->all();
    }
}
