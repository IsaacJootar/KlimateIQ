<?php

namespace Tests\Feature;

use App\Jobs\IngestRegionSignalJob;
use App\Models\Region;
use App\Models\ScoringIndex;
use App\Models\Sector;
use App\Models\User;
use App\Support\IndexCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The sector-first workspace. Sectors are the primary control: a user's sectors define which
 * indices their dashboard shows (App\Support\IndexCoverage). An explicit per-index refinement
 * only narrows within the sector set. All writes go through App\Actions\WriteCoverage.
 */
class CoverageSectorTest extends TestCase
{
    use RefreshDatabase;

    private function sectorIds(array $codes): array
    {
        return Sector::query()->whereIn('code', $codes)->pluck('sector_id')->all();
    }

    private function indexIds(array $codes): array
    {
        return ScoringIndex::query()->whereIn('code', $codes)->pluck('index_id')->all();
    }

    /** @return array<string> the codes of the indices this user's dashboard would show */
    private function visibleIndexCodes(User $user): array
    {
        return IndexCoverage::resolve($user->fresh(), null)['available']->pluck('code')->sort()->values()->all();
    }

    private function saveWorkspace(User $user, array $payload): void
    {
        $this->actingAs($user)
            ->put(route('coverage.update'), array_merge(['region_scope' => 'all'], $payload))
            ->assertRedirect();
    }

    public function test_the_workspace_page_renders_the_sectors(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('coverage.edit'))
            ->assertOk()
            ->assertSee('Public Health & Epidemiology')
            ->assertSee('Agriculture & Food Security');
    }

    public function test_the_dashboard_nudges_users_who_have_no_sectors(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Focus your dashboard on what you monitor');
    }

    public function test_the_dashboard_nudge_disappears_once_a_sector_is_picked(): void
    {
        $user = User::factory()->create();
        $this->saveWorkspace($user, ['sector_ids' => $this->sectorIds(['PUBLIC_HEALTH'])]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Focus your dashboard on what you monitor');
    }

    public function test_the_dashboard_and_regions_headers_name_the_followed_sector(): void
    {
        $user = User::factory()->create();
        $this->saveWorkspace($user, ['sector_ids' => $this->sectorIds(['AGRICULTURE'])]);

        // The header uses the short sector name and links it to the sector home page.
        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('>Agriculture</a>', false)
            ->assertSee(route('sectors.show', 'AGRICULTURE'), false);

        $this->actingAs($user)->get(route('regions.index'))
            ->assertOk()
            ->assertSee('>Agriculture</a>', false);
    }

    public function test_a_users_sectors_define_which_indices_the_dashboard_shows(): void
    {
        $user = User::factory()->create();

        $this->saveWorkspace($user, [
            'sector_ids' => $this->sectorIds(['PUBLIC_HEALTH']),
            'index_ids' => $this->indexIds(['MALARIA_RISK', 'WATERBORNE_DISEASE_RISK', 'RESPIRATORY_RISK', 'HEAT_STRESS_RISK']),
        ]);

        $this->assertSame(['PUBLIC_HEALTH'], $user->sectorSubscriptions()->with('sector')->get()->pluck('sector.code')->all());
        // Kept everything the sector contains -> no refinement stored, sectors drive.
        $this->assertSame(0, $user->indexSubscriptions()->count());
        $this->assertEqualsCanonicalizing(
            ['MALARIA_RISK', 'WATERBORNE_DISEASE_RISK', 'RESPIRATORY_RISK', 'HEAT_STRESS_RISK'],
            $this->visibleIndexCodes($user),
        );
    }

    public function test_a_new_index_added_to_a_followed_sector_appears_automatically(): void
    {
        $user = User::factory()->create();
        $this->saveWorkspace($user, ['sector_ids' => $this->sectorIds(['WATER_SANITATION'])]);

        $baseline = $this->visibleIndexCodes($user);
        $this->assertContains('FLOOD_RISK', $baseline);
        $this->assertNotContains('SYNTHETIC_ROADMAP_INDEX', $baseline);

        // A future roadmap index attached to the sector — no action from the user.
        $newIndex = ScoringIndex::query()->create(['code' => 'SYNTHETIC_ROADMAP_INDEX', 'name' => 'Synthetic Roadmap Index']);
        Sector::query()->where('code', 'WATER_SANITATION')->firstOrFail()->indices()->attach($newIndex->index_id);

        $this->assertEqualsCanonicalizing(
            [...$baseline, 'SYNTHETIC_ROADMAP_INDEX'],
            $this->visibleIndexCodes($user),
        );
    }

    public function test_hiding_an_index_within_a_sector_persists_as_a_refinement(): void
    {
        $user = User::factory()->create();

        $this->saveWorkspace($user, [
            'sector_ids' => $this->sectorIds(['PUBLIC_HEALTH']),
            // dropped RESPIRATORY_RISK
            'index_ids' => $this->indexIds(['MALARIA_RISK', 'HEAT_STRESS_RISK']),
        ]);

        $this->assertEqualsCanonicalizing(
            ['MALARIA_RISK', 'HEAT_STRESS_RISK'],
            $user->indexSubscriptions()->with('index')->get()->pluck('index.code')->all(),
        );
        $this->assertEqualsCanonicalizing(['MALARIA_RISK', 'HEAT_STRESS_RISK'], $this->visibleIndexCodes($user));
    }

    public function test_a_refinement_can_never_widen_beyond_the_sector_set(): void
    {
        $user = User::factory()->create();

        $this->saveWorkspace($user, [
            'sector_ids' => $this->sectorIds(['AGRICULTURE']),
            // FLOOD_RISK isn't in Agriculture — it must be ignored
            'index_ids' => $this->indexIds(['DROUGHT_RISK', 'FLOOD_RISK']),
        ]);

        $this->assertSame(['DROUGHT_RISK'], $this->visibleIndexCodes($user));
    }

    public function test_clearing_all_sectors_returns_to_see_everything(): void
    {
        $user = User::factory()->create();
        $this->saveWorkspace($user, ['sector_ids' => $this->sectorIds(['PUBLIC_HEALTH'])]);

        $this->saveWorkspace($user, ['sector_ids' => []]);

        $this->assertSame(0, $user->sectorSubscriptions()->count());
        $this->assertSame(0, $user->indexSubscriptions()->count());
        $this->assertSame(
            ScoringIndex::query()->count(),
            IndexCoverage::resolve($user->fresh(), null)['available']->count(),
        );
    }

    public function test_narrowing_to_a_dormant_region_activates_it_and_triggers_first_ingestion(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $dormant = Region::query()->firstOrFail();

        $this->saveWorkspace($user, [
            'sector_ids' => $this->sectorIds(['PUBLIC_HEALTH']),
            'region_scope' => 'specific',
            'region_ids' => [$dormant->region_id],
        ]);

        $this->assertTrue($user->regionSubscriptions()->where('region_id', $dormant->region_id)->exists());
        Queue::assertPushed(IngestRegionSignalJob::class);
    }

    public function test_region_scope_all_clears_region_subscriptions(): void
    {
        $user = User::factory()->create();
        $region = Region::query()->firstOrFail();
        $user->regionSubscriptions()->create(['region_id' => $region->region_id]);

        $this->saveWorkspace($user, ['sector_ids' => [], 'region_scope' => 'all']);

        $this->assertSame(0, $user->regionSubscriptions()->count());
    }

    // Clarity Pass B2 — the tab strip groups indices under their sector.

    public function test_resolve_groups_available_indices_under_their_sector(): void
    {
        $user = User::factory()->create();
        $this->saveWorkspace($user, ['sector_ids' => $this->sectorIds(['PUBLIC_HEALTH', 'AGRICULTURE'])]);

        $groups = IndexCoverage::resolve($user->fresh(), null)['groups'];

        // One row per followed sector, in sort order, each carrying that sector's indices.
        $this->assertSame(['PUBLIC_HEALTH', 'AGRICULTURE'], $groups->pluck('sector.code')->all());
        $this->assertContains('MALARIA_RISK', $groups->firstWhere('sector.code', 'PUBLIC_HEALTH')['indices']->pluck('code')->all());
        $this->assertContains('DROUGHT_RISK', $groups->firstWhere('sector.code', 'AGRICULTURE')['indices']->pluck('code')->all());

        // Every available index lands in exactly one group.
        $grouped = $groups->flatMap(fn ($g) => $g['indices']->pluck('code'));
        $this->assertSame($grouped->count(), $grouped->unique()->count());
    }

    public function test_an_index_in_two_followed_sectors_appears_once_under_the_first(): void
    {
        $user = User::factory()->create();
        // Flood sits in both Emergency Response (sort 3) and Water & Sanitation (sort 4).
        $this->saveWorkspace($user, ['sector_ids' => $this->sectorIds(['WATER_SANITATION', 'EMERGENCY_RESPONSE'])]);

        $groups = IndexCoverage::resolve($user->fresh(), null)['groups'];
        $floodRows = $groups->filter(fn ($g) => $g['indices']->pluck('code')->contains('FLOOD_RISK'));

        $this->assertCount(1, $floodRows);
        $this->assertSame('EMERGENCY_RESPONSE', $floodRows->first()['sector']->code);
    }

    public function test_the_dashboard_tab_strip_groups_and_reorders_by_sector(): void
    {
        $user = User::factory()->create();
        $this->saveWorkspace($user, ['sector_ids' => $this->sectorIds(['PUBLIC_HEALTH', 'AGRICULTURE', 'EMERGENCY_RESPONSE'])]);

        // The sector short names head their rows, in configured order; and grouped order puts every
        // Public Health index ahead of Agriculture — the opposite of the flat alphabetical order,
        // where "Agriculture Stress Index" sorts before "Malaria Risk Index".
        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'Public Health', 'Malaria Risk Index',
                'Agriculture', 'Agriculture Stress Index',
                'Emergency Response', 'Flood Risk Index',
            ]);
    }

    public function test_a_single_followed_sector_gets_a_flat_strip(): void
    {
        $user = User::factory()->create();
        $this->saveWorkspace($user, ['sector_ids' => $this->sectorIds(['PUBLIC_HEALTH'])]);

        $groups = IndexCoverage::resolve($user->fresh(), null)['groups'];
        $this->assertCount(1, $groups);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Malaria Risk Index');
    }
}
