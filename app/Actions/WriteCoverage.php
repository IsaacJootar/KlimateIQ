<?php

namespace App\Actions;

use App\Jobs\IngestRegionSignalJob;
use App\Models\Region;
use App\Models\Sector;
use App\Models\User;
use App\Models\UserIndexSubscription;
use App\Models\UserRegionSubscription;
use App\Models\UserSectorSubscription;
use App\Support\IngestionWindow;

/**
 * Persists a user's whole coverage picture in one place — sectors, indices and regions — and
 * kicks off first ingestion for any region going from dormant to watched. Shared by the
 * Workspace page (CoveragePreferenceController) and the first-run onboarding wizard.
 *
 * Effective coverage is still just `user_index_subscriptions` / `user_region_subscriptions`
 * (an empty set means "see everything", per App\Support\IndexCoverage). Sectors are recorded
 * as intent and expanded to the index rows the rest of the app already understands — the
 * caller decides whether to expand (via ::indicesForSectors()) or pass an explicit list.
 */
class WriteCoverage
{
    /**
     * @param  array<int>  $sectorIds
     * @param  array<int>  $indexIds  the final explicit index list; empty = "no index filter"
     * @param  'all'|'specific'  $regionScope
     * @param  array<int>|null  $regionIds
     */
    public function __invoke(
        User $user,
        array $sectorIds,
        array $indexIds,
        string $regionScope = 'all',
        ?array $regionIds = null,
    ): void {
        $this->writeSectors($user, $sectorIds);
        $this->writeIndices($user, $indexIds);
        $this->writeRegions($user, $regionScope === 'specific' ? ($regionIds ?? []) : null);
    }

    /**
     * Every index_id contained in the given sectors, de-duplicated. Used by callers that want
     * "picked a sector, didn't refine" to mean "all of that sector's indices".
     *
     * @param  array<int>  $sectorIds
     * @return array<int>
     */
    public static function indicesForSectors(array $sectorIds): array
    {
        if ($sectorIds === []) {
            return [];
        }

        return Sector::query()
            ->whereIn('sector_id', $sectorIds)
            ->with('indices')
            ->get()
            ->flatMap(fn (Sector $sector) => $sector->indices->pluck('index_id'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int>  $sectorIds
     */
    private function writeSectors(User $user, array $sectorIds): void
    {
        UserSectorSubscription::where('user_id', $user->id)->delete();

        foreach (array_unique($sectorIds) as $sectorId) {
            UserSectorSubscription::create(['user_id' => $user->id, 'sector_id' => $sectorId]);
        }
    }

    /**
     * @param  array<int>  $indexIds
     */
    private function writeIndices(User $user, array $indexIds): void
    {
        UserIndexSubscription::where('user_id', $user->id)->delete();

        foreach (array_unique($indexIds) as $indexId) {
            UserIndexSubscription::create([
                'user_id' => $user->id,
                'index_id' => $indexId,
                'wants_alerts' => true,
            ]);
        }
    }

    /**
     * Verbatim from the old CoveragePreferenceController::update()/triggerFirstIngestion() —
     * a region going from dormant to watched shouldn't leave the user staring at "no data"
     * for up to a week.
     *
     * @param  array<int>|null  $regionIds  null = "all regions" (no filter)
     */
    private function writeRegions(User $user, ?array $regionIds): void
    {
        UserRegionSubscription::where('user_id', $user->id)->delete();

        if ($regionIds === null) {
            return;
        }

        // Dormant regions among the ones being picked — nobody has ever pulled data for them
        // and nobody else is currently watching them either. Must be checked before creating
        // the subscription rows below, or every region would look "already watched".
        $newlyActivated = Region::query()
            ->whereIn('region_id', $regionIds)
            ->whereDoesntHave('signals')
            ->whereDoesntHave('subscribers')
            ->get();

        foreach ($regionIds as $regionId) {
            UserRegionSubscription::create(['user_id' => $user->id, 'region_id' => $regionId]);
        }

        foreach ($newlyActivated as $region) {
            $this->triggerFirstIngestion($region);
        }
    }

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
