<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\User;
use App\Models\UserRegionSubscription;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionsIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

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
}
