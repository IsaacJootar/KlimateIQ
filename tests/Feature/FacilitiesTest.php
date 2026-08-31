<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Region;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use App\Models\User;
use App\Services\Facilities\FacilityProvider;
use App\Services\Facilities\Grid3StaticProvider;
use Database\Seeders\FacilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Clarity Pass D1 — the facilities register. Real places per LGA (from GRID3), behind a
 * FacilityProvider so a live source can replace the static table later. Visible payoff: a
 * "places to reach first" line in the public-health / air-quality recommendations, plus a
 * "see all facilities in this LGA" page.
 */
class FacilitiesTest extends TestCase
{
    use RefreshDatabase;

    private function ikeja(): Region
    {
        return Region::query()->where('name', 'Ikeja')->where('state', 'Lagos')->firstOrFail();
    }

    private function score(Region $region, string $indexCode, ?float $score): void
    {
        $index = ScoringIndex::query()->where('code', $indexCode)->firstOrFail();

        RegionScore::query()->updateOrCreate(
            ['region_id' => $region->region_id, 'index_id' => $index->index_id, 'period_start' => Carbon::parse('2026-08-10')],
            [
                'period_end' => Carbon::parse('2026-08-16'),
                'score' => $score,
                'scoring_strategy' => 'formula',
                'breakdown' => [['signal_type_code' => 'RAINFALL', 'signal_type_name' => 'Rainfall', 'raw_value' => 120, 'unit' => 'mm', 'normalized_score' => 60, 'weight' => 1.0, 'contribution_to_final_score' => $score]],
                'calculated_at' => now(),
            ],
        );
    }

    public function test_the_seeder_populates_the_curated_lgas_and_resolves_region_ids(): void
    {
        $ikeja = Facility::query()->where('region_id', $this->ikeja()->region_id)->get();

        $this->assertGreaterThanOrEqual(4, $ikeja->count());
        $this->assertTrue($ikeja->contains('name', 'Lagos State University Teaching Hospital (LASUTH)'));
        $this->assertEqualsCanonicalizing(['health', 'school', 'market'], $ikeja->pluck('type')->unique()->values()->all());
    }

    public function test_the_provider_is_bound_from_config_and_returns_capped_typed_results(): void
    {
        $this->assertInstanceOf(Grid3StaticProvider::class, app(FacilityProvider::class));

        $health = app(FacilityProvider::class)->forRegion($this->ikeja(), ['health'], 2);

        $this->assertCount(2, $health);
        $this->assertTrue($health->every(fn (Facility $f) => $f->type === 'health'));
        // sort_order 0 first
        $this->assertSame('Lagos State University Teaching Hospital (LASUTH)', $health->first()->name);
    }

    public function test_all_for_region_is_grouped_by_type_and_attribution_carries_the_year(): void
    {
        $grouped = app(FacilityProvider::class)->allForRegion($this->ikeja());

        $this->assertTrue($grouped->has('health'));
        $this->assertTrue($grouped->has('school'));
        $this->assertSame('GRID3, 2023', app(FacilityProvider::class)->attribution());
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $before = Facility::query()->count();
        $this->seed(FacilitySeeder::class);

        $this->assertSame($before, Facility::query()->count());
    }

    public function test_an_amber_public_health_score_names_places_on_the_region_page(): void
    {
        $user = User::factory()->create();
        $region = $this->ikeja();
        $this->score($region, 'MALARIA_RISK', 71.0);

        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'MALARIA_RISK']))
            ->assertOk()
            ->assertSee('Places in Ikeja to reach first', false)
            ->assertSee('Lagos State University Teaching Hospital (LASUTH)', false)
            ->assertSee('On record (GRID3, 2023)', false)
            ->assertSee(route('regions.facilities', $region->region_id), false);
    }

    public function test_a_low_score_or_non_health_index_names_no_places(): void
    {
        $user = User::factory()->create();
        $region = $this->ikeja();

        $this->score($region, 'MALARIA_RISK', 12.0);
        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'MALARIA_RISK']))
            ->assertOk()
            ->assertDontSee('to reach first');

        $this->score($region, 'FLOOD_RISK', 80.0);
        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'FLOOD_RISK']))
            ->assertOk()
            ->assertDontSee('to reach first');
    }

    public function test_the_see_all_facilities_page_lists_them_by_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('regions.facilities', $this->ikeja()->region_id))
            ->assertOk()
            ->assertSee('facilities on record', false)
            ->assertSee('Health facilities', false)
            ->assertSee('Ikeja Grammar School', false)
            ->assertSee('confirm which are open', false);
    }

    public function test_an_lga_with_no_facilities_shows_an_honest_empty_state(): void
    {
        $user = User::factory()->create();
        $bare = Region::query()->whereDoesntHave('facilities')->firstOrFail();

        $this->actingAs($user)->get(route('regions.facilities', $bare->region_id))
            ->assertOk()
            ->assertSee('No facilities are on record', false);
    }
}
