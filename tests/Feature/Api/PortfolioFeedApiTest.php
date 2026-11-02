<?php

namespace Tests\Feature\Api;

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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Enterprise pivot, Module 5 (docs/ENTERPRISE_BUILD_PLAN.md) — Sector #1's licensed data feed.
 * The feed itself is PortfolioRollupService's existing output (covered by PortfolioRollupTest);
 * what's new here is the per-portfolio token scoping, so these tests are about access control,
 * not scoring correctness.
 */
class PortfolioFeedApiTest extends TestCase
{
    use RefreshDatabase;

    private function portfolioWithScore(): Portfolio
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $portfolio = Portfolio::query()->create(['agency_id' => $agency->agency_id, 'name' => 'Loan book', 'type' => 'point_list']);

        $region = Region::query()->firstOrFail();
        $index = ScoringIndex::query()->where('code', 'FLOOD_RISK')->firstOrFail();
        [$start, $end] = IngestionWindow::lastComplete();

        RegionScore::query()->create([
            'index_id' => $index->index_id,
            'region_id' => $region->region_id,
            'period_start' => $start,
            'period_end' => $end,
            'score' => 85,
            'scoring_strategy' => 'formula',
        ]);
        PortfolioLocation::query()->create(['portfolio_id' => $portfolio->portfolio_id, 'region_id' => $region->region_id]);

        return $portfolio;
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $portfolio = $this->portfolioWithScore();

        $this->getJson("/api/v1/portfolios/{$portfolio->portfolio_id}/feed")->assertStatus(401);
    }

    public function test_a_token_without_this_portfolios_ability_is_rejected(): void
    {
        $portfolio = $this->portfolioWithScore();
        $user = User::factory()->create(['agency_id' => $portfolio->agency_id]);
        Sanctum::actingAs($user, ['portfolio:999999']); // a different portfolio entirely

        $this->getJson("/api/v1/portfolios/{$portfolio->portfolio_id}/feed")->assertStatus(403);
    }

    public function test_a_token_scoped_to_this_portfolio_returns_the_feed(): void
    {
        $portfolio = $this->portfolioWithScore();
        $user = User::factory()->create(['agency_id' => $portfolio->agency_id]);
        Sanctum::actingAs($user, [$portfolio->feedAbility()]);

        $response = $this->getJson("/api/v1/portfolios/{$portfolio->portfolio_id}/feed")->assertOk();

        $response->assertJsonPath('portfolio', 'Loan book');
        $response->assertJsonPath('locations.0.worst_score', 85);
        $response->assertJsonPath('locations.0.worst_band', 'red');
        $response->assertJsonPath('locations.0.worst_index_code', 'FLOOD_RISK');
    }

    public function test_a_token_whose_user_has_left_the_agency_is_rejected(): void
    {
        $portfolio = $this->portfolioWithScore();
        $user = User::factory()->create(['agency_id' => $portfolio->agency_id]);
        Sanctum::actingAs($user, [$portfolio->feedAbility()]);

        $user->forceFill(['agency_id' => null])->save();

        $this->getJson("/api/v1/portfolios/{$portfolio->portfolio_id}/feed")->assertStatus(403);
    }

    /**
     * A genuine plaintext bearer token (not Sanctum::actingAs()'s test shortcut), round-tripped
     * against the real endpoint — proves the full issuance-to-authentication path works, not
     * just the ability check in isolation. Issued directly via Eloquent rather than through the
     * web route: going through actingAs() + a real HTTP POST would leave a 'web' session cookie
     * on this test's client, and Sanctum's guard checks that session before the bearer token —
     * see the TransientToken test below for why that matters and how the controller guards
     * against it.
     */
    public function test_an_issued_tokens_plaintext_actually_authenticates_against_the_real_feed_endpoint(): void
    {
        $portfolio = $this->portfolioWithScore();
        $user = User::factory()->create(['agency_id' => $portfolio->agency_id]);
        $plainTextToken = $user->createToken('Acme Re', [$portfolio->feedAbility()])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$plainTextToken}")
            ->getJson("/api/v1/portfolios/{$portfolio->portfolio_id}/feed");

        $response->assertOk()->assertJsonPath('portfolio', 'Loan book');
    }

    public function test_a_token_issued_for_one_portfolio_cannot_read_a_different_portfolio_in_the_same_agency(): void
    {
        $portfolio = $this->portfolioWithScore();
        $otherPortfolio = Portfolio::query()->create(['agency_id' => $portfolio->agency_id, 'name' => 'Other book', 'type' => 'point_list']);
        $user = User::factory()->create(['agency_id' => $portfolio->agency_id]);
        $plainTextToken = $user->createToken('Scoped', [$portfolio->feedAbility()])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$plainTextToken}")
            ->getJson("/api/v1/portfolios/{$otherPortfolio->portfolio_id}/feed")
            ->assertStatus(403);
    }

    /**
     * Sanctum's `auth:sanctum` guard checks the web session before the bearer token — a logged-in
     * agency user's own browser session authenticates with a full-access TransientToken
     * (laravel/sanctum Guard::__invoke), bypassing per-portfolio ability scoping entirely. Harmless
     * for the platform's other read endpoints; not here, where the whole point is that an agency
     * can only read what it was licensed for. The controller explicitly rejects session auth.
     */
    public function test_a_logged_in_browser_session_cannot_use_its_own_cookie_to_read_a_portfolio_feed(): void
    {
        $portfolio = $this->portfolioWithScore();
        $user = User::factory()->create(['agency_id' => $portfolio->agency_id]);

        $this->actingAs($user)->getJson("/api/v1/portfolios/{$portfolio->portfolio_id}/feed")->assertStatus(403);
    }
}
