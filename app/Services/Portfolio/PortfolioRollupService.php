<?php

namespace App\Services\Portfolio;

use App\Models\Portfolio;
use App\Models\PortfolioLocation;
use App\Models\Region;
use App\Models\ScoringIndex;
use App\Support\AgencyIndexCoverage;
use App\Support\LatestScore;
use App\Support\NearestRegion;
use Illuminate\Support\Collection;

/**
 * Enterprise pivot, Module 2/3 (docs/ENTERPRISE_BUILD_PLAN.md) — rolls a Portfolio's locations up
 * into one ranked view: every location, every index its agency's Portfolio Type exposes
 * (App\Support\AgencyIndexCoverage), worst-to-best. Deliberately no new scoring logic — every
 * number comes from the same App\Support\LatestScore reader the region drill-down page already
 * uses, just iterated across a client's own list instead of one region at a time.
 *
 * A coordinate-only location (no seeded Region — see PortfolioLocation::isCoordinateOnly()) is
 * scored against the nearest seeded LGA (App\Support\NearestRegion, Module 3) when one is close
 * enough to be meaningful — but the result always carries `approximated => true` and the
 * distance, never presented as an exact point score. Beyond NearestRegion::MAX_USEFUL_DISTANCE_KM
 * (or with no seeded regions at all nearby), it's honestly marked `scorable => false` instead of
 * approximating against something irrelevant — same fail-safe discipline as an uncalibrated
 * river reach showing "not calibrated yet" rather than a fabricated number.
 */
class PortfolioRollupService
{
    public const TOO_FAR_REASON = 'No seeded LGA is close enough to this coordinate to approximate from.';

    /**
     * @return Collection<int, array{
     *     location: PortfolioLocation,
     *     display_name: string,
     *     scorable: bool,
     *     approximated: bool,
     *     nearest_region_name: ?string,
     *     distance_km: ?float,
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
     * @return array{location: PortfolioLocation, display_name: string, scorable: bool, approximated: bool, nearest_region_name: ?string, distance_km: ?float, reason: ?string, scores: Collection, worst_index_code: ?string, worst_index_name: ?string, worst_score: ?float, worst_band: ?string}
     */
    private function scoreLocation(PortfolioLocation $location, Collection $indices): array
    {
        if (! $location->isCoordinateOnly()) {
            return [
                ...$this->scoreAgainstRegion($location->region, $indices),
                'location' => $location,
                'display_name' => $location->displayName(),
                'approximated' => false,
                'nearest_region_name' => null,
                'distance_km' => null,
            ];
        }

        $nearest = NearestRegion::find((float) $location->latitude, (float) $location->longitude);

        if ($nearest === null) {
            return [
                'location' => $location,
                'display_name' => $location->displayName(),
                'scorable' => false,
                'approximated' => false,
                'nearest_region_name' => null,
                'distance_km' => null,
                'reason' => self::TOO_FAR_REASON,
                'scores' => collect(),
                'worst_index_code' => null,
                'worst_index_name' => null,
                'worst_score' => null,
                'worst_band' => null,
            ];
        }

        return [
            ...$this->scoreAgainstRegion($nearest['region'], $indices),
            'location' => $location,
            'display_name' => $location->displayName(),
            'approximated' => true,
            'nearest_region_name' => $nearest['region']->name,
            'distance_km' => round($nearest['distance_km'], 1),
        ];
    }

    /**
     * The shared scoring pass, used both for a location's own seeded region and for the nearest
     * region standing in for a raw coordinate (Module 3). Paired with the ScoringIndex itself
     * (not just its code) so the "worst index" name is available to the caller directly — a
     * portfolio page showing "Flood Risk — 82" doesn't need a second query to resolve it.
     *
     * @param  Collection<int, ScoringIndex>  $indices
     * @return array{scorable: bool, reason: ?string, scores: Collection, worst_index_code: ?string, worst_index_name: ?string, worst_score: ?float, worst_band: ?string}
     */
    private function scoreAgainstRegion(Region $region, Collection $indices): array
    {
        $entries = $indices
            ->map(fn (ScoringIndex $index) => ['index' => $index, 'result' => LatestScore::for($region, $index)])
            ->filter(fn (array $entry) => $entry['result'] !== null); // no score row for this region yet — a coverage gap, not a zero

        $scoredEntries = $entries->filter(fn (array $entry) => $entry['result']['score'] !== null);
        $worstCode = $scoredEntries->sortByDesc(fn (array $entry) => $entry['result']['score'])->map(fn (array $entry) => $entry['index']->code)->first();
        $worstEntry = $worstCode !== null ? $scoredEntries->first(fn (array $entry) => $entry['index']->code === $worstCode) : null;

        return [
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
