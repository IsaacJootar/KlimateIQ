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

/**
 * Enterprise pivot, Module 13 (docs/ENTERPRISE_BUILD_PLAN.md) — Scenario Testing. A transparent
 * multiplier over real, already-computed scores — these tests cover the math (capped at 100, null
 * stays null) and the page's access control; not a new scoring pass, so no new score-correctness
 * coverage is needed beyond what PortfolioRollupTest already proves.
 */
class PortfolioStressTestTest extends TestCase
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

    public function test_apply_stress_multiplies_the_score_and_caps_at_100(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Book', 'type' => 'point_list']);
        $regionA = Region::query()->orderBy('region_id')->first();
        $regionB = Region::query()->orderBy('region_id')->skip(1)->first();
        $this->score($regionA, 'FLOOD_RISK', 50);
        $this->score($regionB, 'FLOOD_RISK', 95);
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $regionA->region_id]);
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $regionB->region_id]);

        $rollup = app(PortfolioRollupService::class);
        $rows = $rollup->rollup($portfolio);
        $stressed = $rollup->applyStress($rows, 0.20);

        $rowA = $stressed->firstWhere('location.region_id', $regionA->region_id);
        $rowB = $stressed->firstWhere('location.region_id', $regionB->region_id);

        $this->assertSame(60.0, $rowA['stressed_score']); // 50 * 1.2
        $this->assertSame('amber', $rowA['stressed_band']);
        $this->assertSame(100.0, $rowB['stressed_score']); // 95 * 1.2 = 114, capped at 100
        $this->assertSame('red', $rowB['stressed_band']);
    }

    public function test_an_unscored_location_stays_unscored_under_stress(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Book', 'type' => 'point_list']);
        $region = Region::query()->firstOrFail();
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $region->region_id]);

        $rollup = app(PortfolioRollupService::class);
        $stressed = $rollup->applyStress($rollup->rollup($portfolio), 0.30);

        $this->assertNull($stressed->first()['stressed_score']);
        $this->assertSame('none', $stressed->first()['stressed_band']);
    }

    public function test_value_summary_accepts_a_band_key_for_the_stressed_scenario(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Book', 'type' => 'point_list']);
        $region = Region::query()->firstOrFail();
        $this->score($region, 'FLOOD_RISK', 50); // amber baseline
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $region->region_id, 'value' => 1000000, 'currency' => 'NGN']);

        $rollup = app(PortfolioRollupService::class);
        $rows = $rollup->rollup($portfolio);
        $stressed = $rollup->applyStress($rows, 0.50); // 50 * 1.5 = 75, red

        $baseline = $rollup->valueSummary($rows)->firstWhere('currency', 'NGN');
        $underStress = $rollup->valueSummary($stressed, 'stressed_band')->firstWhere('currency', 'NGN');

        $this->assertSame(1000000.0, $baseline['atRisk']); // already amber, already "at risk"
        $this->assertSame(1000000.0, $underStress['atRisk']); // still at risk, now red
    }

    public function test_the_page_renders_the_baseline_with_no_preset_selected(): void
    {
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Book', 'type' => 'point_list']);

        $this->actingAs($user)->get(route('portfolios.stress-test', $portfolio))
            ->assertOk()
            ->assertSee('Illustrative only')
            ->assertSee('Baseline');
    }

    public function test_selecting_a_preset_shows_the_before_and_after_comparison(): void
    {
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Book', 'type' => 'point_list']);
        $region = Region::query()->firstOrFail();
        $this->score($region, 'FLOOD_RISK', 50);
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $region->region_id]);

        $this->actingAs($user)->get(route('portfolios.stress-test', [$portfolio, 'preset' => '30']))
            ->assertOk()
            ->assertSee('Severe (+30%)')
            ->assertSee('65'); // 50 * 1.3
    }

    public function test_an_unrecognized_preset_falls_back_to_the_baseline(): void
    {
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Book', 'type' => 'point_list']);

        $this->actingAs($user)->get(route('portfolios.stress-test', [$portfolio, 'preset' => 'nonsense']))
            ->assertOk()
            ->assertSee('Baseline');
    }

    public function test_a_user_from_a_different_agency_cannot_view_the_stress_test(): void
    {
        $owner = $this->userWithAgency();
        $outsider = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $owner->agency_id, 'name' => 'Private book', 'type' => 'point_list']);

        $this->actingAs($outsider)->get(route('portfolios.stress-test', $portfolio))->assertForbidden();
    }
}
