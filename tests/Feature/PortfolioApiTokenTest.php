<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Portfolio;
use App\Models\User;
use App\Support\PortfolioType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enterprise pivot, Module 5 (docs/ENTERPRISE_BUILD_PLAN.md) — issuing/revoking the portfolio-
 * scoped API token from a portfolio's own page. End-to-end authentication against the feed
 * itself is covered by Tests\Feature\Api\PortfolioFeedApiTest; this is about who may issue or
 * revoke a token for a given portfolio.
 */
class PortfolioApiTokenTest extends TestCase
{
    use RefreshDatabase;

    private function userWithAgency(): User
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);

        return User::factory()->create(['agency_id' => $agency->agency_id]);
    }

    private function portfolioFor(User $user, string $name = 'Loan book'): Portfolio
    {
        return Portfolio::query()->create(['agency_id' => $user->agency_id, 'name' => $name, 'type' => 'point_list']);
    }

    public function test_an_agency_user_can_issue_a_token_and_see_the_plaintext_once(): void
    {
        $user = $this->userWithAgency();
        $portfolio = $this->portfolioFor($user);

        $response = $this->actingAs($user)->post(route('portfolios.tokens.store', $portfolio), ['name' => 'Acme Re']);

        $response->assertRedirect();
        $response->assertSessionHas('plainTextToken');
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
            'name' => 'Acme Re',
        ]);
    }

    public function test_the_issued_tokens_ability_is_scoped_to_exactly_this_portfolio(): void
    {
        $user = $this->userWithAgency();
        $portfolio = $this->portfolioFor($user);

        $this->actingAs($user)->post(route('portfolios.tokens.store', $portfolio), ['name' => 'Acme Re']);

        $token = $user->tokens()->where('name', 'Acme Re')->firstOrFail();
        $this->assertSame([$portfolio->feedAbility()], $token->abilities);
    }

    public function test_a_user_from_a_different_agency_cannot_issue_a_token_for_this_portfolio(): void
    {
        $owner = $this->userWithAgency();
        $outsider = $this->userWithAgency();
        $portfolio = $this->portfolioFor($owner);

        $this->actingAs($outsider)->post(route('portfolios.tokens.store', $portfolio), ['name' => 'Should not exist'])
            ->assertForbidden();

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'Should not exist']);
    }

    public function test_the_portfolio_owner_can_revoke_a_token(): void
    {
        $user = $this->userWithAgency();
        $portfolio = $this->portfolioFor($user);
        $this->actingAs($user)->post(route('portfolios.tokens.store', $portfolio), ['name' => 'Acme Re']);
        $token = $user->tokens()->where('name', 'Acme Re')->firstOrFail();

        $this->actingAs($user)->delete(route('portfolios.tokens.destroy', [$portfolio, $token]))->assertRedirect();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_a_user_from_a_different_agency_cannot_revoke_this_portfolios_token(): void
    {
        $owner = $this->userWithAgency();
        $outsider = $this->userWithAgency();
        $portfolio = $this->portfolioFor($owner);
        $this->actingAs($owner)->post(route('portfolios.tokens.store', $portfolio), ['name' => 'Acme Re']);
        $token = $owner->tokens()->where('name', 'Acme Re')->firstOrFail();

        $this->actingAs($outsider)->delete(route('portfolios.tokens.destroy', [$portfolio, $token]))->assertForbidden();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_a_token_belonging_to_a_different_portfolio_cannot_be_revoked_through_this_one(): void
    {
        $user = $this->userWithAgency();
        $portfolioA = $this->portfolioFor($user, 'Book A');
        $portfolioB = $this->portfolioFor($user, 'Book B');
        $this->actingAs($user)->post(route('portfolios.tokens.store', $portfolioA), ['name' => 'For A']);
        $token = $user->tokens()->where('name', 'For A')->firstOrFail();

        $this->actingAs($user)->delete(route('portfolios.tokens.destroy', [$portfolioB, $token]))->assertStatus(404);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_the_show_page_lists_only_tokens_scoped_to_this_portfolio(): void
    {
        $user = $this->userWithAgency();
        $portfolioA = $this->portfolioFor($user, 'Book A');
        $portfolioB = $this->portfolioFor($user, 'Book B');
        $this->actingAs($user)->post(route('portfolios.tokens.store', $portfolioA), ['name' => 'Token A']);
        $this->actingAs($user)->post(route('portfolios.tokens.store', $portfolioB), ['name' => 'Token B']);

        // One throwaway visit to consume the "Token B" issuance flash message still pending in
        // the session from the POST above, so the real assertion below checks the issued-tokens
        // table, not a one-request-only flash banner that happens to also mention its name.
        $this->actingAs($user)->get(route('portfolios.show', $portfolioA));

        $this->actingAs($user)->get(route('portfolios.show', $portfolioA))
            ->assertSee('Token A')
            ->assertDontSee('Token B');
    }
}
