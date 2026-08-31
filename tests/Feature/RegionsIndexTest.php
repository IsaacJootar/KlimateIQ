<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\ScoringIndex;
use App\Models\User;
use App\Models\UserIndexSubscription;
use App\Models\UserRegionSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionsIndexTest extends TestCase
{
    use RefreshDatabase;

    /** Makes a region "active" the same way a real user would — by watching it. */
    private function activate(Region $region): void
    {
        UserRegionSubscription::create(['user_id' => User::factory()->create()->id, 'region_id' => $region->region_id]);
    }

    public function test_a_user_with_no_coverage_sees_every_active_region(): void
    {
        $user = User::factory()->create();
        [$active, $other] = Region::query()->take(2)->get();
        $this->activate($active);

        $response = $this->actingAs($user)->get(route('regions.index'));

        $response->assertSee($active->name);
        $response->assertDontSee($other->name);
    }

    public function test_the_active_index_shows_its_plain_language_description(): void
    {
        $user = User::factory()->create();
        $this->activate(Region::query()->first());

        $this->actingAs($user)->get(route('regions.index', ['index' => 'MALARIA_RISK']))
            ->assertOk()
            ->assertSee('for programme officers pre-positioning nets', false);
    }

    /**
     * Regression test: the Regions page previously only respected an explicit ?regions=
     * URL parameter and otherwise always showed every active region, silently ignoring
     * a user's own /coverage selection — contradicting the Dashboard, which did respect
     * it, and the coverage page's own "your dashboard and region list default to these"
     * copy.
     */
    public function test_a_users_own_coverage_selection_scopes_the_regions_page(): void
    {
        $user = User::factory()->create();
        [$mine, $someoneElses] = Region::query()->take(2)->get();
        $this->activate($mine);
        $this->activate($someoneElses);
        UserRegionSubscription::create(['user_id' => $user->id, 'region_id' => $mine->region_id]);

        $response = $this->actingAs($user)->get(route('regions.index'));

        $response->assertSee($mine->name);
        $response->assertDontSee($someoneElses->name);
    }

    public function test_an_explicit_regions_parameter_overrides_the_users_own_coverage(): void
    {
        $user = User::factory()->create();
        [$inCoverage, $viaLink] = Region::query()->take(2)->get();
        $this->activate($inCoverage);
        $this->activate($viaLink);
        UserRegionSubscription::create(['user_id' => $user->id, 'region_id' => $inCoverage->region_id]);

        $response = $this->actingAs($user)->get(route('regions.index', ['regions' => $viaLink->region_id]));

        $response->assertSee($viaLink->name);
        $response->assertDontSee($inCoverage->name);
    }

    /**
     * Regression test: the index pill-tabs at the top of this page used to always list every
     * index regardless of what the user configured in Coverage, unlike the Dashboard (which
     * already scoped its tabs to the user's selection) — reported live as an inconsistency
     * between the two pages.
     */
    public function test_index_tabs_are_scoped_to_the_users_coverage_selection(): void
    {
        $user = User::factory()->create();
        $flood = ScoringIndex::where('code', 'FLOOD_RISK')->firstOrFail();
        UserIndexSubscription::create(['user_id' => $user->id, 'index_id' => $flood->index_id, 'wants_alerts' => true]);

        $response = $this->actingAs($user)->get(route('regions.index'));

        $response->assertSee('Flood Risk Index');
        $response->assertDontSee('Malaria Risk Index');
    }

    public function test_no_index_coverage_shows_every_index_tab(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('regions.index'));

        $response->assertSee('Malaria Risk Index');
        $response->assertSee('Flood Risk Index');
        $response->assertSee('Composite Climate-Health Pressure Index');
    }
}
