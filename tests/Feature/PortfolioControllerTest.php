<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Portfolio;
use App\Models\PortfolioLocation;
use App\Models\Region;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use App\Models\User;
use App\Services\Portfolio\PortfolioRollupService;
use App\Support\IngestionWindow;
use App\Support\PortfolioType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioControllerTest extends TestCase
{
    use RefreshDatabase;

    private function userWithAgency(): User
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);

        return User::factory()->create(['agency_id' => $agency->agency_id]);
    }

    public function test_the_create_page_renders(): void
    {
        $user = $this->userWithAgency();

        $this->actingAs($user)->get(route('portfolios.create'))->assertOk()->assertSee('New Portfolio');
    }

    public function test_the_show_page_renders_with_a_mix_of_scored_unscored_and_coordinate_locations(): void
    {
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Mixed book', 'type' => 'point_list']);
        $region = Region::query()->firstOrFail();

        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $region->region_id]);
        // A coordinate near the seeded region approximates successfully (Module 3)...
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'latitude' => $region->latitude, 'longitude' => $region->longitude, 'label' => 'Site X']);
        // ...while one far from anything seeded is honestly marked not scorable, not guessed.
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'latitude' => 0.0, 'longitude' => 0.0, 'label' => 'Out of range']);

        $this->actingAs($user)->get(route('portfolios.show', $portfolio))
            ->assertOk()
            ->assertSee('Mixed book')
            ->assertSee($region->name)
            ->assertSee('Site X')
            ->assertSee('nearest LGA') // Site X's approximation note
            ->assertSee('Out of range')
            ->assertSee(PortfolioRollupService::TOO_FAR_REASON);
    }

    public function test_the_show_page_offers_the_map_a_point_per_asset(): void
    {
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Mapped book', 'type' => 'point_list']);
        $region = Region::query()->whereNotNull('latitude')->firstOrFail();

        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $region->region_id]);
        // A coordinate-only asset plots at its own raw point, not the region's centroid.
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'latitude' => 6.1, 'longitude' => 3.1, 'label' => 'Raw site']);

        $response = $this->actingAs($user)->get(route('portfolios.show', $portfolio))->assertOk();

        $mapAssets = $response->viewData('mapAssets');
        $this->assertCount(2, $mapAssets);

        $regionBacked = $mapAssets->firstWhere('name', $region->name);
        $this->assertEquals((float) $region->latitude, $regionBacked['latitude']);
        $this->assertEquals((float) $region->longitude, $regionBacked['longitude']);

        $rawSite = $mapAssets->firstWhere('name', 'Raw site');
        $this->assertSame(6.1, $rawSite['latitude']);
        $this->assertSame(3.1, $rawSite['longitude']);
    }

    public function test_the_show_page_hides_the_map_and_shows_a_nudge_when_theres_nothing_to_plot(): void
    {
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Empty book', 'type' => 'point_list']);

        $this->actingAs($user)->get(route('portfolios.show', $portfolio))
            ->assertOk()
            ->assertDontSee('id="portfolio-map"', false)
            ->assertSee("it'll appear here", false);
    }

    public function test_the_index_page_renders_with_no_portfolios(): void
    {
        $user = $this->userWithAgency();

        $this->actingAs($user)->get(route('portfolios.index'))->assertOk()->assertSee('Nothing under protection yet');
    }

    public function test_a_user_without_an_agency_cannot_create_a_portfolio(): void
    {
        $user = User::factory()->create(['agency_id' => null]);

        $this->actingAs($user)->get(route('portfolios.create'))->assertForbidden();
    }

    public function test_a_user_with_an_agency_can_create_a_portfolio(): void
    {
        $user = $this->userWithAgency();

        $response = $this->actingAs($user)->post(route('portfolios.store'), [
            'name' => 'My loan book',
            'type' => 'point_list',
        ]);

        $portfolio = Portfolio::query()->where('name', 'My loan book')->firstOrFail();
        $response->assertRedirect(route('portfolios.show', $portfolio));
        $this->assertSame($user->agency_id, $portfolio->agency_id);
    }

    public function test_a_user_can_add_and_remove_a_region_location(): void
    {
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Test', 'type' => 'point_list']);
        $region = Region::query()->firstOrFail();

        $this->actingAs($user)->post(route('portfolios.locations.store', $portfolio), [
            'region_id' => $region->region_id,
        ])->assertRedirect();

        $this->assertDatabaseHas('portfolio_locations', [
            'portfolio_id' => $portfolio->portfolio_id,
            'region_id' => $region->region_id,
        ]);

        $location = PortfolioLocation::query()->where('portfolio_id', $portfolio->portfolio_id)->firstOrFail();

        $this->actingAs($user)->delete(route('portfolios.locations.destroy', [$portfolio, $location]))->assertRedirect();
        $this->assertDatabaseMissing('portfolio_locations', ['portfolio_location_id' => $location->portfolio_location_id]);
    }

    public function test_a_user_can_add_a_coordinate_location(): void
    {
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Towers', 'type' => 'point_list']);

        $this->actingAs($user)->post(route('portfolios.locations.store', $portfolio), [
            'latitude' => 6.5244,
            'longitude' => 3.3792,
            'label' => 'Tower #1',
        ])->assertRedirect();

        $this->assertDatabaseHas('portfolio_locations', [
            'portfolio_id' => $portfolio->portfolio_id,
            'region_id' => null,
            'label' => 'Tower #1',
        ]);
    }

    public function test_a_user_can_record_a_locations_value_and_currency(): void
    {
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Loan book', 'type' => 'point_list']);
        $region = Region::query()->firstOrFail();

        $this->actingAs($user)->post(route('portfolios.locations.store', $portfolio), [
            'region_id' => $region->region_id,
            'value' => 2000000,
            'currency' => 'NGN',
        ])->assertRedirect();

        $this->assertDatabaseHas('portfolio_locations', [
            'portfolio_id' => $portfolio->portfolio_id,
            'region_id' => $region->region_id,
            'value' => 2000000,
            'currency' => 'NGN',
        ]);
    }

    public function test_a_value_without_a_currency_fails_validation(): void
    {
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Loan book', 'type' => 'point_list']);
        $region = Region::query()->firstOrFail();

        $this->actingAs($user)->post(route('portfolios.locations.store', $portfolio), [
            'region_id' => $region->region_id,
            'value' => 2000000,
        ])->assertSessionHasErrors(['currency']);
    }

    public function test_the_value_summary_totals_protected_and_at_risk_value_per_currency(): void
    {
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Loan book', 'type' => 'point_list']);
        $regions = Region::query()->orderBy('region_id')->take(3)->get();

        $index = ScoringIndex::query()->where('code', 'FLOOD_RISK')->firstOrFail();
        [$start, $end] = IngestionWindow::lastComplete();
        RegionScore::query()->create([
            'index_id' => $index->index_id, 'region_id' => $regions[0]->region_id,
            'period_start' => $start, 'period_end' => $end, 'score' => 85, 'scoring_strategy' => 'formula',
        ]);
        RegionScore::query()->create([
            'index_id' => $index->index_id, 'region_id' => $regions[1]->region_id,
            'period_start' => $start, 'period_end' => $end, 'score' => 10, 'scoring_strategy' => 'formula',
        ]);

        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $regions[0]->region_id, 'value' => 2000000, 'currency' => 'NGN']);
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $regions[1]->region_id, 'value' => 1000000, 'currency' => 'NGN']);
        // No value entered — must not be silently counted as zero or inflate either total.
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $regions[2]->region_id]);

        $response = $this->actingAs($user)->get(route('portfolios.show', $portfolio))->assertOk();

        $summary = $response->viewData('valueSummary')->firstWhere('currency', 'NGN');
        $this->assertSame(3000000.0, $summary['total']);
        $this->assertSame(2000000.0, $summary['atRisk']); // only the red-band location
        $this->assertSame(1800000.0, $summary['expectedLoss']); // (2,000,000 * 0.85) + (1,000,000 * 0.10)
        $this->assertSame('₦', $summary['symbol']);
    }

    public function test_adding_a_location_with_neither_region_nor_coordinate_fails_validation(): void
    {
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Test', 'type' => 'point_list']);

        $this->actingAs($user)->post(route('portfolios.locations.store', $portfolio), [])
            ->assertSessionHasErrors(['region_id', 'latitude']);
    }

    public function test_a_corridor_portfolio_assigns_incrementing_order(): void
    {
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Route A', 'type' => 'corridor']);
        $regions = Region::query()->orderBy('region_id')->take(2)->get();

        foreach ($regions as $region) {
            $this->actingAs($user)->post(route('portfolios.locations.store', $portfolio), ['region_id' => $region->region_id]);
        }

        $orders = PortfolioLocation::query()->where('portfolio_id', $portfolio->portfolio_id)->orderBy('portfolio_location_id')->pluck('order')->all();

        $this->assertSame([0, 1], $orders);
    }

    public function test_a_user_from_a_different_agency_cannot_view_the_portfolio(): void
    {
        $owner = $this->userWithAgency();
        $outsider = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $owner->agency_id, 'name' => 'Private book', 'type' => 'point_list']);

        $this->actingAs($outsider)->get(route('portfolios.show', $portfolio))->assertForbidden();
    }

    public function test_a_user_from_a_different_agency_cannot_delete_the_portfolio(): void
    {
        $owner = $this->userWithAgency();
        $outsider = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $owner->agency_id, 'name' => 'Private book', 'type' => 'point_list']);

        $this->actingAs($outsider)->delete(route('portfolios.destroy', $portfolio))->assertForbidden();
        $this->assertModelExists($portfolio);
    }

    public function test_the_index_page_only_lists_the_users_own_agency_portfolios(): void
    {
        $user = $this->userWithAgency();
        $other = $this->userWithAgency();
        Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Mine', 'type' => 'point_list']);
        Portfolio::query()->create(['agency_id' => $other->agency_id, 'name' => 'Not mine', 'type' => 'point_list']);

        $this->actingAs($user)->get(route('portfolios.index'))
            ->assertSee('Mine')
            ->assertDontSee('Not mine');
    }
}
