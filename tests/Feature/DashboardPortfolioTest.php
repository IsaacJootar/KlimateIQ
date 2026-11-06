<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Portfolio;
use App\Models\PortfolioLocation;
use App\Models\Region;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use App\Models\User;
use App\Support\IngestionWindow;
use App\Support\PortfolioType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enterprise pivot, Module 4 (docs/ENTERPRISE_BUILD_PLAN.md) — the dashboard fork point.
 * A user whose agency has a Portfolio sees the new "ranked by risk" view; everyone else
 * keeps the legacy dashboard (DashboardDefaultIndexTest guards that path directly).
 */
class DashboardPortfolioTest extends TestCase
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

    public function test_a_user_whose_agency_has_a_portfolio_sees_the_portfolio_dashboard(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $user = User::factory()->create(['agency_id' => $agency->agency_id]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Loan book', 'type' => 'point_list']);

        $red = Region::query()->orderBy('region_id')->firstOrFail();
        $this->score($red, 'FLOOD_RISK', 85);
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $red->region_id]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Loan book')
            ->assertSee($red->name)
            ->assertDontSee('Active Regions') // a legacy dashboard metric tile, should not render here
            ->assertDontSee('Pick your sectors');
    }

    public function test_a_user_without_an_agency_sees_the_legacy_dashboard(): void
    {
        $user = User::factory()->create(['agency_id' => null]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('ranked by risk');
    }

    public function test_a_user_whose_agency_has_no_portfolio_yet_sees_the_legacy_dashboard(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $user = User::factory()->create(['agency_id' => $agency->agency_id]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('ranked by risk');
    }

    public function test_the_portfolio_dashboard_counts_red_amber_and_unscored_locations(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $user = User::factory()->create(['agency_id' => $agency->agency_id]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Loan book', 'type' => 'point_list']);

        $regions = Region::query()->orderBy('region_id')->take(3)->get();
        $this->score($regions[0], 'FLOOD_RISK', 85); // red
        $this->score($regions[1], 'FLOOD_RISK', 40); // amber
        // $regions[2] gets no score at all — unscored.

        foreach ($regions as $region) {
            PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $region->region_id]);
        }

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $response->assertViewHas('redCount', 1);
        $response->assertViewHas('amberCount', 1);
        $response->assertViewHas('unscoredCount', 1);
    }

    public function test_the_dashboard_shows_protected_and_at_risk_value_when_assets_are_priced(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $user = User::factory()->create(['agency_id' => $agency->agency_id]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Loan book', 'type' => 'point_list']);

        $red = Region::query()->orderBy('region_id')->firstOrFail();
        $this->score($red, 'FLOOD_RISK', 85);
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $red->region_id, 'value' => 2000000, 'currency' => 'NGN']);

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $summary = $response->viewData('valueSummary')->firstWhere('currency', 'NGN');
        $this->assertSame(2000000.0, $summary['total']);
        $this->assertSame(2000000.0, $summary['atRisk']);
        $this->assertSame(1700000.0, $summary['expectedLoss']); // 2,000,000 * (85 / 100)
        $response->assertSee('₦2,000,000', false);
        $response->assertSee('Expected Loss');
    }

    public function test_the_most_recently_created_portfolio_is_shown_when_an_agency_has_several(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $user = User::factory()->create(['agency_id' => $agency->agency_id]);

        $older = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Older book', 'type' => 'point_list']);
        $older->forceFill(['created_at' => now()->subDay()])->save();
        Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Newer book', 'type' => 'point_list']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Newer book')
            ->assertSee('1 other portfolio');
    }
}
