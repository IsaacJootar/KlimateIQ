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
 * Covers the "Use my portfolio" shortcut added to the Reports page — a portfolio user shouldn't
 * have to rebuild their region list from scratch in the raw multi-select every time.
 */
class ReportRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_renders_without_an_agency(): void
    {
        $user = User::factory()->create(['agency_id' => null]);

        $this->actingAs($user)->get(route('reports.index'))
            ->assertOk()
            ->assertDontSee('Use "');
    }

    public function test_a_portfolio_user_sees_the_use_my_portfolio_shortcut(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $user = User::factory()->create(['agency_id' => $agency->agency_id]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Insurance Book', 'type' => 'point_list']);
        $region = Region::query()->firstOrFail();
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $region->region_id]);

        $this->actingAs($user)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Use "Insurance Book"', false);
    }

    public function test_an_agency_user_with_no_portfolio_yet_sees_no_shortcut(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $user = User::factory()->create(['agency_id' => $agency->agency_id]);

        $this->actingAs($user)->get(route('reports.index'))
            ->assertOk()
            ->assertDontSee('Use "');
    }
}
