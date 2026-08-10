<?php

namespace App\Support;

use App\Models\ScoringIndex;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Shared between DashboardController and RegionController — both need the same answer to "which
 * indices can this user switch between, and which one is active right now."
 */
class IndexCoverage
{
    /**
     * @return array{available: Collection<int, ScoringIndex>, active: ScoringIndex}
     */
    public static function resolve(User $user, ?string $requestedCode): array
    {
        $subscribed = ScoringIndex::query()
            ->whereIn('index_id', $user->indexSubscriptions()->pluck('index_id'))
            ->get();

        // Tabs: the indices this user actually picked in Coverage. No index preference set at
        // all means "show everything," matching how an empty region selection already behaves.
        $available = $subscribed->isNotEmpty()
            ? $subscribed->sortBy('name')->values()
            : ScoringIndex::query()->orderBy('name')->get();

        // An explicit ?index= (clicking a tab) wins if it's one of this user's available
        // indices. Otherwise: a single subscribed index is used as-is; with several and no
        // explicit "primary" concept to ask the user for, Composite Climate-Health Pressure —
        // the one index designed to be "the overall snapshot" — is the more defensible default
        // than an arbitrary pick; alphabetical order is the fallback tiebreak, at least
        // predictable instead of coincidental. See DashboardController's git history for the
        // real bug this replaced (defaulting to whichever subscribed index had the lowest
        // database ID, which in practice always meant Malaria Risk).
        $active = $available->firstWhere('code', $requestedCode)
            ?? match (true) {
                $subscribed->isEmpty() => ScoringIndex::where('code', 'COMPOSITE_PRESSURE')->first(),
                $subscribed->count() === 1 => $subscribed->first(),
                default => $subscribed->firstWhere('code', 'COMPOSITE_PRESSURE')
                    ?? $subscribed->sortBy('name')->first(),
            };

        return ['available' => $available, 'active' => $active];
    }
}
