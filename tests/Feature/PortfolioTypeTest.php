<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyIndexOverride;
use App\Models\ScoringIndex;
use App\Models\User;
use App\Support\AgencyIndexCoverage;
use App\Support\PortfolioType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enterprise pivot, Module 1 (docs/ENTERPRISE_BUILD_PLAN.md) — Portfolio Type drives which
 * indices an agency sees by default; AgencyIndexOverride layers per-agency exceptions on top.
 */
class PortfolioTypeTest extends TestCase
{
    use RefreshDatabase;

    private function indexCode(string $code): ScoringIndex
    {
        return ScoringIndex::query()->where('code', $code)->firstOrFail();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['platform_role' => 'PLATFORM_ADMIN'])->save();

        return $user;
    }

    public function test_an_agency_with_no_portfolio_type_sees_everything(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => null]);

        $visible = AgencyIndexCoverage::resolve($agency);

        $this->assertSame(ScoringIndex::query()->count(), $visible->count());
    }

    public function test_insurance_reinsurance_sees_its_default_bundle_only(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);

        $codes = AgencyIndexCoverage::resolve($agency)->pluck('code')->all();

        $this->assertEqualsCanonicalizing(['FLOOD_RISK', 'RIVERINE_FLOOD_FORECAST', 'DROUGHT_RISK'], $codes);
    }

    public function test_public_health_and_agriculture_mirror_the_existing_sector_bundles(): void
    {
        $health = Agency::factory()->create(['portfolio_type' => PortfolioType::PublicHealth]);
        $agric = Agency::factory()->create(['portfolio_type' => PortfolioType::Agriculture]);

        $this->assertEqualsCanonicalizing(
            ['MALARIA_RISK', 'WATERBORNE_DISEASE_RISK', 'RESPIRATORY_RISK', 'HEAT_STRESS_RISK', 'MENINGITIS_RISK'],
            AgencyIndexCoverage::resolve($health)->pluck('code')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['DROUGHT_RISK', 'AGRICULTURE_STRESS', 'IRRIGATION_NEED', 'RANGELAND_STRESS', 'PLANTING_WINDOW_RISK'],
            AgencyIndexCoverage::resolve($agric)->pluck('code')->all(),
        );
    }

    public function test_an_override_can_add_an_index_outside_the_default_bundle(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $wildfire = $this->indexCode('WILDFIRE_RISK');

        AgencyIndexOverride::query()->create([
            'agency_id' => $agency->agency_id,
            'index_id' => $wildfire->index_id,
            'enabled' => true,
        ]);

        $codes = AgencyIndexCoverage::resolve($agency)->pluck('code')->all();

        $this->assertContains('WILDFIRE_RISK', $codes);
        $this->assertContains('FLOOD_RISK', $codes); // default bundle still applies elsewhere
    }

    public function test_an_override_can_remove_an_index_inside_the_default_bundle(): void
    {
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $drought = $this->indexCode('DROUGHT_RISK');

        AgencyIndexOverride::query()->create([
            'agency_id' => $agency->agency_id,
            'index_id' => $drought->index_id,
            'enabled' => false,
        ]);

        $codes = AgencyIndexCoverage::resolve($agency)->pluck('code')->all();

        $this->assertNotContains('DROUGHT_RISK', $codes);
        $this->assertContains('FLOOD_RISK', $codes);
    }

    public function test_admin_can_toggle_an_override_through_the_controller(): void
    {
        $admin = $this->admin();
        $agency = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $wildfire = $this->indexCode('WILDFIRE_RISK');

        // Not in the default bundle -> toggling enables it.
        $this->actingAs($admin)
            ->patch(route('admin.agencies.toggle-index', $agency), ['index_id' => $wildfire->index_id])
            ->assertRedirect();

        $this->assertTrue(
            AgencyIndexOverride::query()->where('agency_id', $agency->agency_id)->where('index_id', $wildfire->index_id)->value('enabled'),
        );

        // Toggling again removes the override entirely (back to "not in default", no row needed).
        $this->actingAs($admin)
            ->patch(route('admin.agencies.toggle-index', $agency), ['index_id' => $wildfire->index_id]);

        $this->assertSame(
            0,
            AgencyIndexOverride::query()->where('agency_id', $agency->agency_id)->where('index_id', $wildfire->index_id)->count(),
        );
    }

    public function test_merging_agencies_carries_over_index_overrides(): void
    {
        $admin = $this->admin();
        $keep = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $duplicate = Agency::factory()->create(['portfolio_type' => PortfolioType::InsuranceReinsurance]);
        $wildfire = $this->indexCode('WILDFIRE_RISK');

        AgencyIndexOverride::query()->create([
            'agency_id' => $duplicate->agency_id,
            'index_id' => $wildfire->index_id,
            'enabled' => true,
        ]);

        $this->actingAs($admin)->post(route('admin.agencies.merge'), [
            'keep_agency_id' => $keep->agency_id,
            'duplicate_agency_id' => $duplicate->agency_id,
        ])->assertRedirect();

        $this->assertTrue(
            AgencyIndexOverride::query()->where('agency_id', $keep->agency_id)->where('index_id', $wildfire->index_id)->value('enabled'),
        );
        $this->assertModelMissing($duplicate);
    }

    public function test_registering_a_new_agency_requires_a_portfolio_type(): void
    {
        $response = $this->post(route('register'), [
            'name' => 'Test User',
            'email' => 'test-pivot@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'designation' => 'OTHER',
            'other_designation' => 'Risk Analyst',
            'state' => 'Lagos',
            'new_agency_name' => 'A Brand New Insurer Ltd',
        ]);

        $response->assertSessionHasErrors('portfolio_type');
    }

    public function test_registering_a_new_agency_with_a_portfolio_type_sets_it(): void
    {
        $this->post(route('register'), [
            'name' => 'Test User',
            'email' => 'test-pivot-2@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'designation' => 'OTHER',
            'other_designation' => 'Risk Analyst',
            'state' => 'Lagos',
            'new_agency_name' => 'Another Brand New Insurer Ltd',
            'portfolio_type' => PortfolioType::InsuranceReinsurance->value,
        ])->assertRedirect(route('onboarding.show'));

        $agency = Agency::query()->where('name', 'Another Brand New Insurer Ltd')->firstOrFail();

        $this->assertSame(PortfolioType::InsuranceReinsurance, $agency->portfolio_type);
    }
}
