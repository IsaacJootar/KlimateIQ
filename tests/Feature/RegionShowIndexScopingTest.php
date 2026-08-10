<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\ScoringIndex;
use App\Models\User;
use App\Models\UserIndexSubscription;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test: reported live at /regions/{id}?index=COMPOSITE_PRESSURE — the region detail
 * page's index tabs still listed every index regardless of the user's Coverage selection, even
 * after the same bug was fixed on the Regions list page and the Dashboard. RegionController::show()
 * was still calling ScoringIndex::all() directly instead of the shared IndexCoverage helper.
 */
class RegionShowIndexScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_index_tabs_on_the_region_page_are_scoped_to_the_users_coverage(): void
    {
        $user = User::factory()->create();
        $region = Region::query()->first();
        $flood = ScoringIndex::where('code', 'FLOOD_RISK')->firstOrFail();
        UserIndexSubscription::create(['user_id' => $user->id, 'index_id' => $flood->index_id, 'wants_alerts' => true]);

        $response = $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'COMPOSITE_PRESSURE']));

        // Composite isn't in this user's coverage, so the explicit ?index= override should fall
        // back to their one configured index (Flood) rather than the URL's unrecognized value.
        $response->assertSee('Flood Risk Index');
        $response->assertDontSee('Malaria Risk Index');
    }

    public function test_no_index_coverage_shows_every_index_tab_on_the_region_page(): void
    {
        $user = User::factory()->create();
        $region = Region::query()->first();

        $response = $this->actingAs($user)->get(route('regions.show', ['region' => $region]));

        $response->assertSee('Malaria Risk Index');
        $response->assertSee('Composite Climate-Health Pressure Index');
    }
}
