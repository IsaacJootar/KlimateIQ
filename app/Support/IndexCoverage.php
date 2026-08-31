<?php

namespace App\Support;

use App\Models\ScoringIndex;
use App\Models\Sector;
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
     * @return array{available: Collection<int, ScoringIndex>, active: ScoringIndex, groups: Collection<int, array{sector: ?Sector, indices: Collection<int, ScoringIndex>}>}
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

        return [
            'available' => $available,
            'active' => $active,
            'groups' => self::groupBySector($user, $available),
        ];
    }

    /**
     * The available indices arranged under sector headings, for the tab strip (Clarity Pass B2).
     * A user who follows sectors sees those, in their configured order; a user who follows none
     * sees every sector. Each index lands under the first sector (by that order) it belongs to,
     * so it appears exactly once. One group or fewer means the caller can render a flat strip —
     * the sector label would just repeat what the page header already says.
     *
     * @param  Collection<int, ScoringIndex>  $available
     * @return Collection<int, array{sector: ?Sector, indices: Collection<int, ScoringIndex>}>
     */
    private static function groupBySector(User $user, Collection $available): Collection
    {
        if ($available->count() <= 1) {
            return collect([['sector' => null, 'indices' => $available->values()]]);
        }

        $active = self::activeSectorIds($user);

        $sectors = Sector::query()
            ->when($active->isNotEmpty(), fn ($q) => $q->whereIn('sector_id', $active))
            ->orderBy('sort_order')
            ->get();

        // index_id => rows of {sector_id, sort_order} restricted to the sectors in play.
        $membership = DB::table('index_sector')
            ->whereIn('sector_id', $sectors->pluck('sector_id'))
            ->get()
            ->groupBy('index_id');

        $buckets = [];      // sector_id => list of [index, pivot sort_order]
        $orphans = collect();

        foreach ($available as $index) {
            $rows = $membership->get($index->index_id);
            $home = $rows === null
                ? null
                : $sectors->first(fn (Sector $s) => $rows->contains('sector_id', $s->sector_id));

            if ($home === null) {
                $orphans->push($index);

                continue;
            }

            $buckets[$home->sector_id][] = [
                'index' => $index,
                'order' => $rows->firstWhere('sector_id', $home->sector_id)->sort_order ?? 0,
            ];
        }

        $groups = $sectors
            ->filter(fn (Sector $s) => isset($buckets[$s->sector_id]))
            ->map(fn (Sector $s) => [
                'sector' => $s,
                'indices' => collect($buckets[$s->sector_id])->sortBy('order')->pluck('index')->values(),
            ])
            ->values();

        if ($orphans->isNotEmpty()) {
            $groups->push(['sector' => null, 'indices' => $orphans->values()]);
        }

        return $groups;
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
        $sectorIds = self::activeSectorIds($user);

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
     * The sectors in play right now: all the user follows, unless they've pinned one via the
     * nav switcher (Clarity Pass B4) — then just that one. A pin is honoured only while it's
     * still a followed sector and the user follows more than one (the switcher isn't shown
     * otherwise, so a stale pin can't silently hide everything).
     *
     * @return Collection<int, int>
     */
    private static function activeSectorIds(User $user): Collection
    {
        $followed = $user->sectorSubscriptions()->pluck('sector_id');

        if ($followed->count() < 2) {
            return $followed;
        }

        $pinned = $user->getOrCreateDashboardPreference()->current_sector_id;

        return $pinned !== null && $followed->contains($pinned)
            ? collect([$pinned])
            : $followed;
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
