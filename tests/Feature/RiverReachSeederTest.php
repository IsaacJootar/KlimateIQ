<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RiverReach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T4/T5 follow-up — the curated Niger/Benue reach dataset (database/seeders/data/
 * nigeria_river_reaches.json) loads cleanly and every entry is grounded.
 */
class RiverReachSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reach_dataset_seeds_and_resolves_to_regions(): void
    {
        $reaches = RiverReach::query()->get();

        $this->assertGreaterThanOrEqual(15, $reaches->count());
        $this->assertTrue($reaches->every(fn (RiverReach $r) => $r->region_id !== null));
        $this->assertTrue($reaches->every(fn (RiverReach $r) => strlen((string) $r->river) >= 4));
        $this->assertContains('Niger', $reaches->pluck('river'));
        $this->assertContains('Benue', $reaches->pluck('river'));
        $this->assertTrue($reaches->every(fn (RiverReach $r) => $r->source !== '' && str_contains($r->source, 'geoBoundaries')));

        // Every coordinate sits inside Nigeria's bounding box.
        $this->assertTrue($reaches->every(fn (RiverReach $r) => $r->latitude > 4 && $r->latitude < 14 && $r->longitude > 2.5 && $r->longitude < 15));
    }

    public function test_lokoja_is_a_multi_river_lga(): void
    {
        $lokoja = Region::query()->where('lga_code', 'NG-KO-480')->first();
        $this->assertNotNull($lokoja, 'Lokoja LGA seeded');

        $rivers = RiverReach::query()->where('region_id', $lokoja->region_id)->pluck('river');
        $this->assertContains('Niger', $rivers);
        $this->assertContains('Benue', $rivers);
    }

    public function test_reseeding_is_idempotent(): void
    {
        $before = RiverReach::query()->count();
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);
        $this->assertSame($before, RiverReach::query()->count());
    }
}
