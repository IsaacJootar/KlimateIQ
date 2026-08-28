<?php

namespace Tests\Feature;

use App\Jobs\IngestRegionSignalJob;
use App\Models\Region;
use App\Models\ScoringIndex;
use App\Models\Sector;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * M2 of the sector-first workspace: the Workspace page persists sectors and expands them to
 * the underlying index subscriptions the rest of the app already understands. All writes go
 * through App\Actions\WriteCoverage.
 */
class CoverageSectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    private function idsFor(string $model, array $codes): array
    {
        return $model::query()->whereIn('code', $codes)->pluck($model === Sector::class ? 'sector_id' : 'index_id')->all();
    }

    private function update(User $user, array $payload): void
    {
        $this->actingAs($user)
            ->put(route('coverage.update'), array_merge([
                'index_scope' => 'specific',
                'region_scope' => 'all',
            ], $payload))
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

    public function test_picking_a_sector_persists_it_and_expands_to_its_indices(): void
    {
        $user = User::factory()->create();

        $this->update($user, [
            'sector_ids' => $this->idsFor(Sector::class, ['PUBLIC_HEALTH']),
            // no index_ids sent -> WriteCoverage derives them from the sector
            'index_ids' => [],
        ]);

        $this->assertSame(['PUBLIC_HEALTH'], $user->sectorSubscriptions()->with('sector')->get()->pluck('sector.code')->all());
        $this->assertEqualsCanonicalizing(
            ['MALARIA_RISK', 'RESPIRATORY_RISK', 'HEAT_STRESS_RISK'],
            $user->indexSubscriptions()->with('index')->get()->pluck('index.code')->all(),
        );
    }

    public function test_an_index_can_be_unticked_within_a_picked_sector(): void
    {
        $user = User::factory()->create();
        $keep = $this->idsFor(ScoringIndex::class, ['MALARIA_RISK', 'HEAT_STRESS_RISK']);

        $this->update($user, [
            'sector_ids' => $this->idsFor(Sector::class, ['PUBLIC_HEALTH']),
            'index_ids' => $keep,
        ]);

        $this->assertEqualsCanonicalizing(
            ['MALARIA_RISK', 'HEAT_STRESS_RISK'],
            $user->indexSubscriptions()->with('index')->get()->pluck('index.code')->all(),
        );
    }

    public function test_index_scope_all_means_no_index_filter_even_with_a_sector_picked(): void
    {
        $user = User::factory()->create();

        $this->update($user, [
            'index_scope' => 'all',
            'sector_ids' => $this->idsFor(Sector::class, ['AGRICULTURE']),
            'index_ids' => $this->idsFor(ScoringIndex::class, ['DROUGHT_RISK']),
        ]);

        // Sector intent is recorded, but effective index coverage is empty = "see everything".
        $this->assertSame(['AGRICULTURE'], $user->sectorSubscriptions()->with('sector')->get()->pluck('sector.code')->all());
        $this->assertSame(0, $user->indexSubscriptions()->count());
    }

    public function test_narrowing_to_a_dormant_region_activates_it_and_triggers_first_ingestion(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $dormant = Region::query()->firstOrFail();

        $this->update($user, [
            'sector_ids' => $this->idsFor(Sector::class, ['PUBLIC_HEALTH']),
            'index_ids' => [],
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

        $this->update($user, [
            'sector_ids' => [],
            'index_ids' => [],
            'region_scope' => 'all',
        ]);

        $this->assertSame(0, $user->regionSubscriptions()->count());
    }
}
