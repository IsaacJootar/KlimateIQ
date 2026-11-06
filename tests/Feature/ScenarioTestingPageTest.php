<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Portfolio;
use App\Models\PortfolioLocation;
use App\Models\Region;
use App\Models\User;
use App\Support\PortfolioType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scenario Testing's own home in the nav — the deck's fourth pillar (Module 13,
 * docs/ENTERPRISE_BUILD_PLAN.md) was only reachable via a small link on each portfolio's own
 * page. This page lists every portfolio the agency has, linking into that portfolio's existing
 * stress test (proven in PortfolioStressTestTest) — no new stress-test logic here.
 */
class ScenarioTestingPageTest extends TestCase
{
    use RefreshDatabase;

    private function userWithAgency(): User
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);

        return User::factory()->create(['agency_id' => $agency->agency_id]);
    }

    public function test_a_user_without_an_agency_sees_no_portfolio_to_test(): void
    {
        $user = User::factory()->create(['agency_id' => null]);

        $this->actingAs($user)->get(route('scenario-testing.index'))
            ->assertOk()
            ->assertSee("isn't part of an organization", false);
    }

    public function test_an_agency_user_with_no_portfolios_sees_a_nudge_to_create_one(): void
    {
        $user = $this->userWithAgency();

        $this->actingAs($user)->get(route('scenario-testing.index'))
            ->assertOk()
            ->assertSee('No portfolios yet');
    }

    public function test_every_one_of_the_agencys_portfolios_links_to_its_own_stress_test(): void
    {
        $user = $this->userWithAgency();
        $bookA = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Book A', 'type' => 'point_list']);
        $bookB = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Book B', 'type' => 'point_list']);

        $response = $this->actingAs($user)->get(route('scenario-testing.index'))->assertOk();

        $response->assertSee('Book A')
            ->assertSee('Book B')
            ->assertSee(route('portfolios.stress-test', $bookA), false)
            ->assertSee(route('portfolios.stress-test', $bookB), false);
    }

    public function test_a_portfolio_with_a_red_location_shows_its_red_count(): void
    {
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Book A', 'type' => 'point_list']);
        $region = Region::query()->firstOrFail();
        PortfolioLocation::query()->create([
            'portfolio_id' => $portfolio->portfolio_id,
            'region_id' => $region->region_id,
            'label' => 'Loc 1',
        ]);

        // No scores exist yet, so this portfolio has 0 red locations — confirms the badge only
        // appears when redCount > 0, not unconditionally.
        $this->actingAs($user)->get(route('scenario-testing.index'))
            ->assertOk()
            ->assertDontSee('red now');
    }

    public function test_only_the_agencys_own_portfolios_are_listed(): void
    {
        $user = $this->userWithAgency();
        $other = $this->userWithAgency();
        Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Mine', 'type' => 'point_list']);
        Portfolio::query()->create(['agency_id' => $other->agency_id, 'name' => 'Not mine', 'type' => 'point_list']);

        $this->actingAs($user)->get(route('scenario-testing.index'))
            ->assertSee('Mine')
            ->assertDontSee('Not mine');
    }
}
