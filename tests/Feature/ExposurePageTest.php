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
 * Enterprise pivot, Module 14 (docs/ENTERPRISE_BUILD_PLAN.md) — one Protected/At Risk total
 * across every portfolio the agency has, not one at a time.
 */
class ExposurePageTest extends TestCase
{
    use RefreshDatabase;

    private function userWithAgency(): User
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);

        return User::factory()->create(['agency_id' => $agency->agency_id]);
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

    public function test_a_user_without_an_agency_sees_nothing_to_total(): void
    {
        $user = User::factory()->create(['agency_id' => null]);

        $this->actingAs($user)->get(route('exposure.index'))
            ->assertOk()
            ->assertSee("isn't part of an organization", false);
    }

    public function test_totals_combine_across_every_portfolio_the_agency_has(): void
    {
        $user = $this->userWithAgency();
        $bookA = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Book A', 'type' => 'point_list']);
        $bookB = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Book B', 'type' => 'point_list']);
        $regionA = Region::query()->orderBy('region_id')->first();
        $regionB = Region::query()->orderBy('region_id')->skip(1)->first();

        $this->score($regionA, 'FLOOD_RISK', 85); // red
        $this->score($regionB, 'FLOOD_RISK', 10); // green
        PortfolioLocation::query()->create(['portfolio_id' => $bookA->portfolio_id, 'region_id' => $regionA->region_id, 'value' => 2000000, 'currency' => 'NGN']);
        PortfolioLocation::query()->create(['portfolio_id' => $bookB->portfolio_id, 'region_id' => $regionB->region_id, 'value' => 1000000, 'currency' => 'NGN']);

        $response = $this->actingAs($user)->get(route('exposure.index'))->assertOk();

        $combined = $response->viewData('combined')->firstWhere('currency', 'NGN');
        $this->assertSame(3000000.0, $combined['total']);
        $this->assertSame(2000000.0, $combined['atRisk']); // only Book A's red-band asset

        $response->assertSee('Book A')->assertSee('Book B');
    }

    public function test_only_the_agencys_own_portfolios_are_totaled(): void
    {
        $user = $this->userWithAgency();
        $other = $this->userWithAgency();
        Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Mine', 'type' => 'point_list']);
        Portfolio::query()->create(['agency_id' => $other->agency_id, 'name' => 'Not mine', 'type' => 'point_list']);

        $this->actingAs($user)->get(route('exposure.index'))
            ->assertSee('Mine')
            ->assertDontSee('Not mine');
    }

    public function test_an_agency_with_no_portfolios_sees_a_nudge_to_create_one(): void
    {
        $user = $this->userWithAgency();

        $this->actingAs($user)->get(route('exposure.index'))
            ->assertOk()
            ->assertSee('No portfolios yet');
    }
}
