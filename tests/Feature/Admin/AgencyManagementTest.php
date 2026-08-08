<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\ReportRequest;
use App\Models\SavedView;
use App\Models\ThresholdConfig;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgencyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['platform_role' => 'PLATFORM_ADMIN'])->save();

        return $user;
    }

    public function test_non_admins_cannot_view_agencies(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.agencies.index'))->assertForbidden();
    }

    public function test_admins_can_view_and_rename_an_agency(): void
    {
        $admin = $this->admin();
        $agency = Agency::factory()->create(['name' => 'Old Name']);

        $this->actingAs($admin)->get(route('admin.agencies.index'))->assertSee('Old Name');

        $this->actingAs($admin)->patch(route('admin.agencies.update', $agency), ['name' => 'New Name']);

        $this->assertSame('New Name', $agency->fresh()->name);
    }

    public function test_merging_reassigns_every_referencing_record_and_deletes_the_duplicate(): void
    {
        $admin = $this->admin();
        $keep = Agency::factory()->create(['name' => 'Keep This One']);
        $duplicate = Agency::factory()->create(['name' => 'Duplicate']);

        $user = User::factory()->create(['agency_id' => $duplicate->agency_id]);
        $region = \App\Models\Region::first();
        $index = \App\Models\ScoringIndex::first();

        $savedView = SavedView::create([
            'user_id' => $user->id,
            'agency_id' => $duplicate->agency_id,
            'name' => 'Test view',
            'region_ids' => [$region->region_id],
            'index_id' => $index->index_id,
        ]);

        $report = ReportRequest::create([
            'user_id' => $user->id,
            'agency_id' => $duplicate->agency_id,
            'index_id' => $index->index_id,
            'region_ids' => [$region->region_id],
            'date_from' => now()->subDays(7),
            'date_to' => now(),
            'format' => 'csv',
            'status' => 'PENDING',
        ]);

        $threshold = ThresholdConfig::create([
            'user_id' => $user->id,
            'agency_id' => $duplicate->agency_id,
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'alert_type' => 'fixed_threshold',
            'comparison_operator' => '>',
            'threshold_value' => 70,
            'active' => true,
        ]);

        $this->actingAs($admin)->post(route('admin.agencies.merge'), [
            'keep_agency_id' => $keep->agency_id,
            'duplicate_agency_id' => $duplicate->agency_id,
        ])->assertRedirect();

        $this->assertSame($keep->agency_id, $user->fresh()->agency_id);
        $this->assertSame($keep->agency_id, $savedView->fresh()->agency_id);
        $this->assertSame($keep->agency_id, $report->fresh()->agency_id);
        $this->assertSame($keep->agency_id, $threshold->fresh()->agency_id);
        $this->assertModelMissing($duplicate);
        $this->assertModelExists($keep);
    }

    public function test_merge_rejects_merging_an_agency_into_itself(): void
    {
        $admin = $this->admin();
        $agency = Agency::factory()->create();

        $response = $this->actingAs($admin)->post(route('admin.agencies.merge'), [
            'keep_agency_id' => $agency->agency_id,
            'duplicate_agency_id' => $agency->agency_id,
        ]);

        $response->assertSessionHasErrors('duplicate_agency_id');
        $this->assertModelExists($agency);
    }
}
