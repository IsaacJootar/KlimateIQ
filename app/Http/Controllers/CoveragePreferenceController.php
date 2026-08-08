<?php

namespace App\Http\Controllers;

use App\Jobs\IngestRegionSignalJob;
use App\Models\Region;
use App\Models\ScoringIndex;
use App\Models\UserIndexSubscription;
use App\Models\UserRegionSubscription;
use App\Support\IngestionWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Lets a user pick which regions and indices they cover — the "one platform, personalized per
 * user" seam. An empty subscription set means "see everything" (the default for a new user);
 * once a user picks specific regions/indices, other pages scope to just those. Always
 * reconfigurable — this never locks a user out of anything, it only changes their default view.
 */
class CoveragePreferenceController extends Controller
{
    public function edit(): View
    {
        $user = Auth::user();

        return view('coverage.edit', [
            'regions' => Region::query()->orderBy('name')->get(),
            'indices' => ScoringIndex::all(),
            'subscribedRegionIds' => $user->regionSubscriptions()->pluck('region_id')->all(),
            'subscribedIndexIds' => $user->indexSubscriptions()->pluck('index_id')->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'region_scope' => ['required', 'in:all,specific'],
            'region_ids' => ['nullable', 'array'],
            'region_ids.*' => ['exists:regions,region_id'],
            'index_scope' => ['required', 'in:all,specific'],
            'index_ids' => ['nullable', 'array'],
            'index_ids.*' => ['exists:indices,index_id'],
        ]);

        $userId = Auth::id();

        UserRegionSubscription::where('user_id', $userId)->delete();
        if ($validated['region_scope'] === 'specific') {
            $regionIds = $validated['region_ids'] ?? [];

            // Dormant regions among the ones being picked — nobody has ever pulled data
            // for them and nobody else is currently watching them either. Must be checked
            // before creating the subscription rows below, or every region would look
            // "already watched" by the time we check.
            $newlyActivated = Region::query()
                ->whereIn('region_id', $regionIds)
                ->whereDoesntHave('signals')
                ->whereDoesntHave('subscribers')
                ->get();

            foreach ($regionIds as $regionId) {
                UserRegionSubscription::create(['user_id' => $userId, 'region_id' => $regionId]);
            }

            foreach ($newlyActivated as $region) {
                $this->triggerFirstIngestion($region);
            }
        }

        UserIndexSubscription::where('user_id', $userId)->delete();
        if ($validated['index_scope'] === 'specific') {
            foreach ($validated['index_ids'] ?? [] as $indexId) {
                UserIndexSubscription::create(['user_id' => $userId, 'index_id' => $indexId, 'wants_alerts' => true]);
            }
        }

        return back()->with('status', 'Coverage updated.');
    }

    /**
     * A region going from dormant to watched shouldn't leave the user staring at "no
     * data" for up to a week — pull its first real data right away, on top of it now
     * joining the normal weekly cycle automatically (see IngestSignalsCommand).
     */
    private function triggerFirstIngestion(Region $region): void
    {
        [$periodStart, $periodEnd] = IngestionWindow::lastComplete();

        foreach (config('ingestion.sources', []) as $serviceClass) {
            IngestRegionSignalJob::dispatch(
                $serviceClass,
                $region->region_id,
                $periodStart->toDateString(),
                $periodEnd->toDateString(),
            );
        }
    }
}
