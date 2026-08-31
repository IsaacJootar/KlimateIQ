<?php

namespace Tests\Feature\Onboarding;

use App\Jobs\IngestRegionSignalJob;
use App\Models\Region;
use App\Models\ScoringIndex;
use App\Models\Sector;
use App\Models\User;
use App\Support\IndexCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OnboardingWizardTest extends TestCase
{
    use RefreshDatabase;

    private function sectorIds(array $codes): array
    {
        return Sector::query()->whereIn('code', $codes)->pluck('sector_id')->all();
    }

    public function test_finishing_writes_the_workspace_and_stamps_onboarded_at(): void
    {
        $user = User::factory()->unonboarded()->create();

        $this->actingAs($user)->post(route('onboarding.store'), [
            'sector_ids' => $this->sectorIds(['PUBLIC_HEALTH']),
            'region_scope' => 'all',
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertNotNull($user->onboarded_at);
        $this->assertSame(['PUBLIC_HEALTH'], $user->sectorSubscriptions()->with('sector')->get()->pluck('sector.code')->all());
        // No refinement stored — the sector drives coverage.
        $this->assertSame(0, $user->indexSubscriptions()->count());
        $this->assertEqualsCanonicalizing(
            ['MALARIA_RISK', 'WATERBORNE_DISEASE_RISK', 'RESPIRATORY_RISK', 'HEAT_STRESS_RISK'],
            IndexCoverage::resolve($user, null)['available']->pluck('code')->all(),
        );
        $this->assertSame(0, $user->regionSubscriptions()->count());
    }

    public function test_unticking_an_index_in_the_wizard_is_kept_as_a_refinement(): void
    {
        $user = User::factory()->unonboarded()->create();
        $keep = ScoringIndex::query()->whereIn('code', ['MALARIA_RISK', 'HEAT_STRESS_RISK'])->pluck('index_id')->all();

        $this->actingAs($user)->post(route('onboarding.store'), [
            'sector_ids' => $this->sectorIds(['PUBLIC_HEALTH']),
            'index_ids' => $keep,
            'region_scope' => 'all',
        ])->assertRedirect(route('dashboard'));

        $this->assertEqualsCanonicalizing(
            ['MALARIA_RISK', 'HEAT_STRESS_RISK'],
            IndexCoverage::resolve($user->fresh(), null)['available']->pluck('code')->all(),
        );
    }

    public function test_the_state_region_option_resolves_to_that_states_lgas(): void
    {
        Queue::fake();

        $user = User::factory()->unonboarded()->create(['state' => 'Kano']);

        $this->actingAs($user)->post(route('onboarding.store'), [
            'sector_ids' => $this->sectorIds(['PUBLIC_HEALTH']),
            'region_scope' => 'state',
        ])->assertRedirect(route('dashboard'));

        $watched = $user->regionSubscriptions()->pluck('region_id');
        $this->assertGreaterThan(0, $watched->count());
        $this->assertTrue(
            Region::query()->whereIn('region_id', $watched)->get()->every(fn (Region $r) => $r->state === 'Kano'),
        );
    }

    public function test_skip_stamps_onboarded_at_with_no_subscriptions(): void
    {
        $user = User::factory()->unonboarded()->create();

        $this->actingAs($user)->post(route('onboarding.skip'))->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertNotNull($user->onboarded_at);
        $this->assertSame(0, $user->sectorSubscriptions()->count());
        $this->assertSame(0, $user->indexSubscriptions()->count());
        $this->assertSame(0, $user->regionSubscriptions()->count());
    }

    public function test_picking_no_sectors_but_finishing_leaves_a_see_everything_workspace(): void
    {
        $user = User::factory()->unonboarded()->create();

        $this->actingAs($user)->post(route('onboarding.store'), [
            'region_scope' => 'all',
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertNotNull($user->onboarded_at);
        $this->assertSame(0, $user->indexSubscriptions()->count());
    }

    public function test_specific_regions_activate_dormant_ones(): void
    {
        Queue::fake();

        $user = User::factory()->unonboarded()->create();
        $region = Region::query()->firstOrFail();

        $this->actingAs($user)->post(route('onboarding.store'), [
            'sector_ids' => $this->sectorIds(['EMERGENCY_RESPONSE']),
            'region_scope' => 'specific',
            'region_ids' => [$region->region_id],
        ])->assertRedirect(route('dashboard'));

        $this->assertTrue($user->regionSubscriptions()->where('region_id', $region->region_id)->exists());
        Queue::assertPushed(IngestRegionSignalJob::class);
    }
}
