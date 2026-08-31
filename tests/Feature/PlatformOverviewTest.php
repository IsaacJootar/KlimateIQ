<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Alert;
use App\Models\Region;
use App\Models\ScoringIndex;
use App\Models\ThresholdConfig;
use App\Models\User;
use App\Models\UserRegionSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_at_a_non_oversight_agency_cannot_view_the_overview(): void
    {
        $agency = Agency::factory()->create(['federal_oversight' => false]);
        $user = User::factory()->create(['agency_id' => $agency->agency_id]);

        $this->actingAs($user)->get(route('overview.index'))->assertForbidden();
    }

    public function test_a_user_with_no_agency_cannot_view_the_overview(): void
    {
        $user = User::factory()->create(['agency_id' => null]);

        $this->actingAs($user)->get(route('overview.index'))->assertForbidden();
    }

    public function test_a_user_at_an_oversight_agency_sees_activity_across_every_agency(): void
    {
        $oversightAgency = Agency::factory()->create(['federal_oversight' => true]);
        $viewer = User::factory()->create(['agency_id' => $oversightAgency->agency_id]);

        $otherAgency = Agency::factory()->create(['name' => 'Some Other Agency', 'federal_oversight' => false]);
        $otherUser = User::factory()->create(['agency_id' => $otherAgency->agency_id]);
        $region = Region::query()->first();
        UserRegionSubscription::create(['user_id' => $otherUser->id, 'region_id' => $region->region_id]);

        $response = $this->actingAs($viewer)->get(route('overview.index'));

        $response->assertOk();
        $response->assertSee('Some Other Agency');
    }

    public function test_open_alerts_are_counted_per_agency_via_the_threshold_configs_agency_id(): void
    {
        $oversightAgency = Agency::factory()->create(['federal_oversight' => true]);
        $viewer = User::factory()->create(['agency_id' => $oversightAgency->agency_id]);

        $otherAgency = Agency::factory()->create(['name' => 'Alerting Agency']);
        $otherUser = User::factory()->create(['agency_id' => $otherAgency->agency_id]);
        $region = Region::query()->first();
        $index = ScoringIndex::query()->first();

        $threshold = ThresholdConfig::create([
            'user_id' => $otherUser->id,
            'agency_id' => $otherAgency->agency_id,
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'alert_type' => 'fixed_threshold',
            'comparison_operator' => '>',
            'threshold_value' => 70,
            'active' => true,
        ]);

        Alert::create([
            'threshold_config_id' => $threshold->threshold_config_id,
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'score_at_trigger' => 80,
            'threshold_value' => 70,
            'status' => 'OPEN',
            'triggered_at' => now(),
        ]);

        $response = $this->actingAs($viewer)->get(route('overview.index'));

        $response->assertOk();
        $response->assertSee('Alerting Agency');
        $response->assertSeeInOrder(['Alerting Agency']);
    }
}
