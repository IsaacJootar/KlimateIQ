<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\IndexActionRecommendation;
use App\Models\Portfolio;
use App\Models\PortfolioLocation;
use App\Models\Region;
use App\Models\RegionForecastScore;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use App\Models\User;
use App\Support\IngestionWindow;
use App\Support\PortfolioType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enterprise pivot, Module 11 (docs/ENTERPRISE_BUILD_PLAN.md) — the region/asset drill-down page,
 * reached via `?portfolio={id}` from a portfolio's map or locations table. Covers the context
 * resolving correctly for a genuine asset, being silently ignored for a forged/mismatched one, and
 * the portfolio-type-scoped "what to do" text winning over the generic version.
 */
class RegionPortfolioContextTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_a_genuine_portfolio_asset_shows_the_breadcrumb_and_value(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $user = User::factory()->create(['agency_id' => $agency->agency_id]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Insurance Book', 'type' => 'point_list']);
        $region = Region::query()->firstOrFail();
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $region->region_id, 'value' => 2000000, 'currency' => 'NGN']);
        $this->score($region, 'FLOOD_RISK', 50);

        $response = $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'FLOOD_RISK', 'portfolio' => $portfolio->portfolio_id]));

        $response->assertOk()
            ->assertSee('Asset in Insurance Book')
            ->assertSee('Back to Insurance Book')
            ->assertSee('₦2,000,000', false);
    }

    public function test_facilities_and_crop_advice_are_hidden_for_a_financial_portfolio_context(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $user = User::factory()->create(['agency_id' => $agency->agency_id]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Insurance Book', 'type' => 'point_list']);
        $region = Region::query()->firstOrFail();
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $region->region_id]);
        $this->score($region, 'FLOOD_RISK', 74);

        // Browsing directly (no portfolio context): the shelter-staging facilities show, same
        // as every other FLOOD_RISK region (covered by FacilitiesTest).
        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'FLOOD_RISK']))
            ->assertSee('to stage from', false);

        // Reached from the Insurance portfolio: the same facilities text must not appear —
        // shelter staging isn't relevant to an underwriting/claims audience.
        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'FLOOD_RISK', 'portfolio' => $portfolio->portfolio_id]))
            ->assertDontSee('to stage from');
    }

    public function test_a_portfolio_from_a_different_agency_is_silently_ignored(): void
    {
        $otherAgency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $otherPortfolio = Portfolio::query()->create(['agency_id' => $otherAgency->agency_id, 'name' => 'Not Yours', 'type' => 'point_list']);
        $region = Region::query()->firstOrFail();
        PortfolioLocation::query()->create(['portfolio_id' => $otherPortfolio->portfolio_id, 'region_id' => $region->region_id]);

        $user = User::factory()->create(['agency_id' => null]);

        $response = $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'portfolio' => $otherPortfolio->portfolio_id]));

        $response->assertOk()
            ->assertDontSee('Not Yours')
            ->assertSee('All regions');
    }

    public function test_a_portfolio_param_for_a_region_not_actually_in_it_is_ignored(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $user = User::factory()->create(['agency_id' => $agency->agency_id]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Insurance Book', 'type' => 'point_list']);
        $inPortfolio = Region::query()->orderBy('region_id')->first();
        $notInPortfolio = Region::query()->orderBy('region_id')->skip(1)->first();
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $inPortfolio->region_id]);

        $response = $this->actingAs($user)->get(route('regions.show', ['region' => $notInPortfolio, 'portfolio' => $portfolio->portfolio_id]));

        $response->assertOk()->assertDontSee('Asset in Insurance Book');
    }

    public function test_the_portfolio_type_scoped_recommendation_wins_over_the_generic_one(): void
    {
        $flood = ScoringIndex::query()->where('code', 'FLOOD_RISK')->firstOrFail();
        IndexActionRecommendation::query()->updateOrCreate(
            ['index_id' => $flood->index_id, 'risk_band' => 'red', 'portfolio_type' => null],
            ['action_text' => 'GENERIC EMERGENCY RESPONSE TEXT']
        );
        IndexActionRecommendation::query()->updateOrCreate(
            ['index_id' => $flood->index_id, 'risk_band' => 'red', 'portfolio_type' => PortfolioType::InsuranceReinsurance->value],
            ['action_text' => 'INSURANCE UNDERWRITING TEXT']
        );

        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $user = User::factory()->create(['agency_id' => $agency->agency_id]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Insurance Book', 'type' => 'point_list']);
        $region = Region::query()->firstOrFail();
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $region->region_id]);
        $this->score($region, 'FLOOD_RISK', 85);

        // With the portfolio context: the type-specific text.
        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'FLOOD_RISK', 'portfolio' => $portfolio->portfolio_id]))
            ->assertSee('INSURANCE UNDERWRITING TEXT')
            ->assertDontSee('GENERIC EMERGENCY RESPONSE TEXT');

        // Browsing the same region directly, with no portfolio context: the generic text.
        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'FLOOD_RISK']))
            ->assertSee('GENERIC EMERGENCY RESPONSE TEXT')
            ->assertDontSee('INSURANCE UNDERWRITING TEXT');
    }

    public function test_portfolio_context_also_applies_on_the_forecast_index_page(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $user = User::factory()->create(['agency_id' => $agency->agency_id]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Insurance Book', 'type' => 'point_list']);
        $region = Region::query()->firstOrFail();
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $region->region_id]);

        $riverine = ScoringIndex::query()->where('code', 'RIVERINE_FLOOD_FORECAST')->firstOrFail();
        RegionForecastScore::query()->insert([
            'index_id' => $riverine->index_id, 'region_id' => $region->region_id,
            'forecast_issued_at' => now()->toDateString(), 'horizon_days' => 15,
            'score' => 75, 'peak_date' => now()->addDays(3)->toDateString(), 'lead_days_to_peak' => 3,
            'scoring_strategy' => 'forecast_formula', 'scoring_version' => 'forecast-formula-v1',
            'breakdown' => json_encode(['daily' => [
                ['date' => now()->toDateString(), 'lead_days' => 0, 'score' => 40, 'signals' => ['RIVER_DISCHARGE' => ['raw_value' => 1600, 'normalized_score' => 40]]],
                ['date' => now()->addDays(3)->toDateString(), 'lead_days' => 3, 'score' => 75, 'signals' => ['RIVER_DISCHARGE' => ['raw_value' => 2960, 'normalized_score' => 75]]],
            ]]),
            'calculated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'RIVERINE_FLOOD_FORECAST', 'portfolio' => $portfolio->portfolio_id]));

        $response->assertOk()->assertSee('Asset in Insurance Book');
    }
}
