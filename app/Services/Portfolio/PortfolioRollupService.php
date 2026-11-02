<?php

namespace App\Services\Portfolio;

use App\Models\Portfolio;
use App\Models\PortfolioLocation;
use App\Models\ScoringIndex;
use App\Support\AgencyIndexCoverage;
use App\Support\LatestScore;
use Illuminate\Support\Collection;

/**
 * Enterprise pivot, Module 2 (docs/ENTERPRISE_BUILD_PLAN.md) — rolls a Portfolio's locations up
 * into one ranked view: every location, every index its agency's Portfolio Type exposes
 * (App\Support\AgencyIndexCoverage), worst-to-best. Deliberately no new scoring logic — every
 * number comes from the same App\Support\LatestScore reader the region drill-down page already
 * uses, just iterated across a client's own list instead of one region at a time.
 *
 * A coordinate-only location (no seeded Region — see PortfolioLocation::isCoordinateOnly()) is
 * never silently scored against a nearby LGA. Point/coordinate scoring is Module 3; until that
 * exists, a coordinate-only row is honestly marked `scorable => false` with a reason, the same
 * fail-safe discipline as an uncalibrated river reach showing "not calibrated yet" instead of a
 * fabricated number.
 */
class PortfolioRollupService
{
    public const UNSCORABLE_REASON = "Point-coordinate scoring isn't built yet (Module 3) — only locations tied to a named LGA can be scored today.";

    /**
     * @return Collection<int, array{
     *     location: PortfolioLocation,
     *     display_name: string,
     *     scorable: bool,
     *     reason: ?string,
     *     scores: Collection<string, array{score: ?float, band: string, as_of: mixed, is_forecast: bool, lead_days: ?int, target_date: mixed}>,
     *     worst_index_code: ?string,
     *     worst_index_name: ?string,
     *     worst_score: ?float,
     *     worst_band: ?string,
     * }>
     */
    public function rollup(Portfolio $portfolio): Collection
    {
        $indices = AgencyIndexCoverage::resolve($portfolio->agency);

        $rows = $portfolio->locations()
            ->with('region')
            ->get()
            ->map(fn (PortfolioLocation $location) => $this->scoreLocation($location, $indices));

        // Worst (highest score = highest risk) first; locations with nothing scored yet sink to
        // the bottom rather than being dropped — a client should see "no data for this one"
        // before a payer ever hides it entirely.
        return $rows->sortByDesc(fn (array $row) => $row['worst_score'] ?? -1)->values();
    }

    /**
     * @param  Collection<int, ScoringIndex>  $indices
     * @return array{location: PortfolioLocation, display_name: string, scorable: bool, reason: ?string, scores: Collection, worst_index_code: ?string, worst_index_name: ?string, worst_score: ?float, worst_band: ?string}
     */
    private function scoreLocation(PortfolioLocation $location, Collection $indices): array
    {
        if ($location->isCoordinateOnly()) {
            return [
                'location' => $location,
                'display_name' => $location->displayName(),
                'scorable' => false,
                'reason' => self::UNSCORABLE_REASON,
                'scores' => collect(),
                'worst_index_code' => null,
                'worst_index_name' => null,
                'worst_score' => null,
                'worst_band' => null,
            ];
        }

        // Paired with the ScoringIndex itself (not just its code) so the "worst index" name is
        // available to the caller directly — a portfolio page showing "Flood Risk — 82" doesn't
        // need a second query to resolve the name from the code.
        $entries = $indices
            ->map(fn (ScoringIndex $index) => ['index' => $index, 'result' => LatestScore::for($location->region, $index)])
            ->filter(fn (array $entry) => $entry['result'] !== null); // no score row for this region yet — a coverage gap, not a zero

        $scoredEntries = $entries->filter(fn (array $entry) => $entry['result']['score'] !== null);
        $worstCode = $scoredEntries->sortByDesc(fn (array $entry) => $entry['result']['score'])->map(fn (array $entry) => $entry['index']->code)->first();
        $worstEntry = $worstCode !== null ? $scoredEntries->first(fn (array $entry) => $entry['index']->code === $worstCode) : null;

        return [
            'location' => $location,
            'display_name' => $location->displayName(),
            'scorable' => true,
            'reason' => null,
            'scores' => $entries->mapWithKeys(fn (array $entry) => [$entry['index']->code => $entry['result']]),
            'worst_index_code' => $worstCode,
            'worst_index_name' => $worstEntry['index']->name ?? null,
            'worst_score' => $worstEntry['result']['score'] ?? null,
            'worst_band' => $worstEntry['result']['band'] ?? null,
        ];
    }
}
