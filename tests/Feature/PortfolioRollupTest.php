<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Portfolio;
use App\Models\PortfolioLocation;
use App\Models\Region;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use App\Services\Portfolio\PortfolioRollupService;
use App\Support\IngestionWindow;
use App\Support\PortfolioType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enterprise pivot, Modules 2 & 3 (docs/ENTERPRISE_BUILD_PLAN.md) — the roll-up is pure
 * aggregation over App\Support\LatestScore, App\Support\AgencyIndexCoverage, and (for a
 * coordinate-only location) App\Support\NearestRegion; no new scoring logic, so these tests are
 * about ranking, coverage gaps, and the nearest-LGA-approximation fail-safe, not scoring
 * correctness (already covered elsewhere).
 */
class PortfolioRollupTest extends TestCase
{
    use RefreshDatabase;

    private function regionAt(int $n): Region
    {
        return Region::query()->orderBy('region_id')->skip($n)->firstOrFail();
    }

    private function score(Region $region, string $indexCode, float $score): void
    {
        $index = ScoringIndex::query()->where('code', $indexCode)->firstOrFail();
        [$start, $end] = IngestionWindow::lastComplete();

        RegionScore::query()->create([
            'index_id' => $index->index_id,
            'region_id' => $region->region_id,
            'period_start' => $start,
            'period_end' => $end,
            'score' => $score,
            'scoring_strategy' => 'formula',
        ]);
    }

    public function test_locations_are_ranked_worst_to_best(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Test book', 'type' => 'point_list']);

        $low = $this->regionAt(0);
        $high = $this->regionAt(1);
        $this->score($low, 'FLOOD_RISK', 20);
        $this->score($high, 'FLOOD_RISK', 85);

        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $low->region_id]);
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $high->region_id]);

        $rows = app(PortfolioRollupService::class)->rollup($portfolio);

        $this->assertSame($high->region_id, $rows->first()['location']->region_id);
        $this->assertSame(85.0, $rows->first()['worst_score']);
        $this->assertSame('FLOOD_RISK', $rows->first()['worst_index_code']);
        $this->assertSame('red', $rows->first()['worst_band']);
        $this->assertSame($low->region_id, $rows->last()['location']->region_id);
    }

    public function test_only_the_agencys_portfolio_type_indices_are_considered(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Test book', 'type' => 'point_list']);
        $region = $this->regionAt(0);

        // MALARIA_RISK isn't in Insurance & Reinsurance's default bundle — even a severe score
        // here shouldn't surface as the "worst index" for this agency.
        $this->score($region, 'MALARIA_RISK', 99);
        $this->score($region, 'DROUGHT_RISK', 40);

        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $region->region_id]);

        $row = app(PortfolioRollupService::class)->rollup($portfolio)->first();

        $this->assertSame('DROUGHT_RISK', $row['worst_index_code']);
        $this->assertSame(40.0, $row['worst_score']);
    }

    public function test_a_location_with_no_scores_yet_sinks_to_the_bottom_not_dropped(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Test book', 'type' => 'point_list']);

        $scored = $this->regionAt(0);
        $unscored = $this->regionAt(1);
        $this->score($scored, 'FLOOD_RISK', 50);

        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $scored->region_id]);
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $unscored->region_id]);

        $rows = app(PortfolioRollupService::class)->rollup($portfolio);

        $this->assertCount(2, $rows);
        $this->assertSame($unscored->region_id, $rows->last()['location']->region_id);
        $this->assertNull($rows->last()['worst_score']);
        $this->assertTrue($rows->last()['scorable']); // a real LGA, just nothing scored yet — different from coordinate-only
    }

    public function test_a_coordinate_near_a_seeded_lga_is_scored_against_it_and_marked_approximated(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::TelecomInfrastructure]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Tower network', 'type' => 'point_list']);

        // Using a seeded region's own coordinates makes it deterministically the nearest match
        // (distance ~0) without depending on real-world geography knowledge in the test itself.
        $nearby = Region::query()->whereNotNull('latitude')->firstOrFail();
        $this->score($nearby, 'FLOOD_RISK', 72);

        PortfolioLocation::query()->create([
            'portfolio_id' => $portfolio->portfolio_id,
            'latitude' => $nearby->latitude,
            'longitude' => $nearby->longitude,
            'label' => 'Tower #42',
        ]);

        $row = app(PortfolioRollupService::class)->rollup($portfolio)->first();

        $this->assertTrue($row['scorable']);
        $this->assertTrue($row['approximated']);
        $this->assertSame($nearby->name, $row['nearest_region_name']);
        $this->assertLessThan(1.0, $row['distance_km']); // same point, allowing for float rounding
        $this->assertSame(72.0, $row['worst_score']);
        $this->assertSame('Tower #42', $row['display_name']); // the location's own label, not the nearest region's name
    }

    public function test_a_coordinate_too_far_from_any_seeded_lga_is_marked_not_scorable(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::TelecomInfrastructure]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Tower network', 'type' => 'point_list']);

        // The Gulf of Guinea — well outside Nigeria, hundreds of km from the nearest seeded LGA.
        PortfolioLocation::query()->create([
            'portfolio_id' => $portfolio->portfolio_id,
            'latitude' => 0.0,
            'longitude' => 0.0,
            'label' => 'Out of range',
        ]);

        $row = app(PortfolioRollupService::class)->rollup($portfolio)->first();

        $this->assertFalse($row['scorable']);
        $this->assertFalse($row['approximated']);
        $this->assertSame(PortfolioRollupService::TOO_FAR_REASON, $row['reason']);
        $this->assertNull($row['worst_score']);
    }

    public function test_an_empty_portfolio_rolls_up_to_an_empty_collection(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Empty', 'type' => 'point_list']);

        $rows = app(PortfolioRollupService::class)->rollup($portfolio);

        $this->assertCount(0, $rows);
    }
}
