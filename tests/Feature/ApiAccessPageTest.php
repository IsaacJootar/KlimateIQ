<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Portfolio;
use App\Models\User;
use App\Support\PortfolioType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enterprise pivot, Module 12 (docs/ENTERPRISE_BUILD_PLAN.md) — the API/token feature (Module 5)
 * gets its own clearly-reachable home instead of being buried at the bottom of one portfolio's
 * page. No new token logic here — this page just lists every portfolio the agency has, each with
 * the same issue/revoke panel already proven in PortfolioApiTokenTest.
 */
class ApiAccessPageTest extends TestCase
{
    use RefreshDatabase;

    private function userWithAgency(): User
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);

        return User::factory()->create(['agency_id' => $agency->agency_id]);
    }

    public function test_a_user_without_an_agency_sees_no_portfolio_to_license(): void
    {
        $user = User::factory()->create(['agency_id' => null]);

        $this->actingAs($user)->get(route('api-access.index'))
            ->assertOk()
            ->assertSee("isn't part of an organization", false);
    }

    public function test_an_agency_user_with_no_portfolios_sees_a_nudge_to_create_one(): void
    {
        $user = $this->userWithAgency();

        $this->actingAs($user)->get(route('api-access.index'))
            ->assertOk()
            ->assertSee('No portfolios yet');
    }

    public function test_every_one_of_the_agencys_portfolios_is_listed_with_its_own_issue_form(): void
    {
        $user = $this->userWithAgency();
        $bookA = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Book A', 'type' => 'point_list']);
        $bookB = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Book B', 'type' => 'point_list']);

        $response = $this->actingAs($user)->get(route('api-access.index'))->assertOk();

        $response->assertSee('Book A')
            ->assertSee('Book B')
            ->assertSee(route('portfolios.tokens.store', $bookA), false)
            ->assertSee(route('portfolios.tokens.store', $bookB), false);
    }

    public function test_issuing_a_token_from_this_page_scopes_it_to_the_right_portfolio(): void
    {
        // The issuance route itself (and that a real plaintext token authenticates against the
        // live feed) is already proven end-to-end in PortfolioFeedApiTest — this just confirms
        // this page's form posts to the correct portfolio-scoped route, not a shared one.
        $user = $this->userWithAgency();
        $portfolio = Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Book A', 'type' => 'point_list']);

        $this->actingAs($user)->post(route('portfolios.tokens.store', $portfolio), ['name' => 'Acme Re']);

        $token = $user->tokens()->where('name', 'Acme Re')->firstOrFail();
        $this->assertSame([$portfolio->feedAbility()], $token->abilities);
    }

    public function test_only_the_agencys_own_portfolios_and_tokens_are_listed(): void
    {
        $user = $this->userWithAgency();
        $other = $this->userWithAgency();
        Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => 'Mine', 'type' => 'point_list']);
        Portfolio::query()->create(['agency_id' => $other->agency_id, 'name' => 'Not mine', 'type' => 'point_list']);

        $this->actingAs($user)->get(route('api-access.index'))
            ->assertSee('Mine')
            ->assertDontSee('Not mine');
    }
}
