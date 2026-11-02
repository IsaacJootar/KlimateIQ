<?php

namespace App\Support;

use App\Models\Agency;
use App\Models\ScoringIndex;
use Illuminate\Support\Collection;

/**
 * Enterprise pivot, Module 1 (docs/ENTERPRISE_BUILD_PLAN.md) — the agency-level counterpart to
 * App\Support\IndexCoverage: which indices does this *agency* see, before any individual user's
 * own preferences are applied. An agency's PortfolioType sets the default bundle
 * (PortfolioType::defaultIndexCodes()); AgencyIndexOverride rows layer per-agency exceptions on
 * top — a row forces an index in (enabled=true) or out (enabled=false) regardless of what the
 * type default says. No portfolio type and no overrides falls back to "show everything", the
 * same default IndexCoverage uses for a user with nothing configured.
 *
 * Deliberately not wired into the dashboard yet — that's Module 4. This class exists on its own
 * first so Module 1 is independently correct and testable before anything downstream depends on
 * it.
 */
class AgencyIndexCoverage
{
    /**
     * @return Collection<int, ScoringIndex>
     */
    public static function resolve(Agency $agency): Collection
    {
        $overrides = $agency->indexOverrides()->get()->keyBy('index_id');

        $defaultCodes = $agency->portfolio_type?->defaultIndexCodes();

        if ($defaultCodes === null && $overrides->isEmpty()) {
            return ScoringIndex::query()->orderBy('name')->get();
        }

        $all = ScoringIndex::query()->orderBy('name')->get();
        $defaultCodes ??= [];

        return $all
            ->filter(function (ScoringIndex $index) use ($defaultCodes, $overrides) {
                $override = $overrides->get($index->index_id);

                if ($override !== null) {
                    return $override->enabled;
                }

                return in_array($index->code, $defaultCodes, true);
            })
            ->values();
    }
}
