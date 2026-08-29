<?php

namespace App\Support;

use App\Models\ScoringIndex;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared between DashboardController and RegionController — both need the same answer to "which
 * indices can this user switch between, and which one is active right now."
 *
 * Sector-primary: a user's sectors define the set of indices they see. A new index added to a
 * sector (see docs/BUILD_PLAN.md) therefore reaches every follower of that sector automatically,
 * with no action from them. An explicit per-index refinement (set on the Workspace page) only
 * ever *narrows* within the sector set — it never widens it. No sectors at all falls back to the
 * legacy behaviour: the user's explicit index picks, or "show everything" if they have none.
 */
class IndexCoverage
{
    /**
     * @return array{available: Collection<int, ScoringIndex>, active: ScoringIndex}
     */
    public static function resolve(User $user, ?string $requestedCode): array
    {
        $available = self::availableIndices($user);

        // An explicit ?index= (clicking a tab) wins if it's one the user can see. Otherwise
        // Composite Climate-Health Pressure — the index designed to be "the overall snapshot" —
        // is the sensible default when it's available; alphabetical order is the fallback
        // tiebreak, at least predictable rather than coincidental (git history: the old default
        // was whichever index had the lowest database id, i.e. always Malaria Risk).
        $active = $available->firstWhere('code', $requestedCode)
            ?? $available->firstWhere('code', 'COMPOSITE_PRESSURE')
            ?? $available->sortBy('name')->first()
            ?? ScoringIndex::query()->orderBy('name')->first();

        return ['available' => $available, 'active' => $active];
    }

    /**
     * @return Collection<int, ScoringIndex>
     */
    private static function availableIndices(User $user): Collection
    {
        $sectorIndexIds = self::sectorIndexIds($user);
        $explicitIds = $user->indexSubscriptions()->pluck('index_id');

        $ids = match (true) {
            // Sectors + a refinement: the refinement narrows within the sector set.
            $sectorIndexIds->isNotEmpty() && $explicitIds->isNotEmpty() => self::firstNonEmpty($sectorIndexIds->intersect($explicitIds), $sectorIndexIds),

            // Sectors, no refinement: everything in those sectors (incl. future additions).
            $sectorIndexIds->isNotEmpty() => $sectorIndexIds,

            // No sectors: legacy explicit picks.
            $explicitIds->isNotEmpty() => $explicitIds,

            // Nothing set: show everything.
            default => null,
        };

        return $ids === null
            ? ScoringIndex::query()->orderBy('name')->get()
            : ScoringIndex::query()->whereIn('index_id', $ids)->orderBy('name')->get();
    }

    /**
     * @return Collection<int, int>
     */
    private static function sectorIndexIds(User $user): Collection
    {
        $sectorIds = $user->sectorSubscriptions()->pluck('sector_id');

        if ($sectorIds->isEmpty()) {
            return collect();
        }

        return DB::table('index_sector')
            ->whereIn('sector_id', $sectorIds)
            ->pluck('index_id')
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, int>  $primary
     * @param  Collection<int, int>  $fallback
     * @return Collection<int, int>
     */
    private static function firstNonEmpty(Collection $primary, Collection $fallback): Collection
    {
        return $primary->isNotEmpty() ? $primary : $fallback;
    }
}
