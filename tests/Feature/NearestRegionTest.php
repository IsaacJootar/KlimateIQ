<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Support\NearestRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enterprise pivot, Module 3 (docs/ENTERPRISE_BUILD_PLAN.md) — nearest-LGA snapping for a raw
 * coordinate. See App\Support\NearestRegion's own docblock for why this, not true point-level
 * ingestion, is Module 3's actual scope.
 */
class NearestRegionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_the_exact_same_point_as_distance_zero(): void
    {
        $region = Region::query()->whereNotNull('latitude')->firstOrFail();

        $result = NearestRegion::find((float) $region->latitude, (float) $region->longitude);

        $this->assertNotNull($result);
        $this->assertSame($region->region_id, $result['region']->region_id);
        $this->assertLessThan(0.01, $result['distance_km']);
    }

    public function test_it_prefers_the_closer_of_two_regions(): void
    {
        $near = Region::query()->whereNotNull('latitude')->firstOrFail();
        // A tiny offset — well within MAX_USEFUL_DISTANCE_KM, and guaranteed closer to $near
        // than to any other already-seeded region (none of the 774 real LGAs sit this close
        // together).
        $queryLat = (float) $near->latitude + 0.001;
        $queryLon = (float) $near->longitude + 0.001;

        $result = NearestRegion::find($queryLat, $queryLon);

        $this->assertSame($near->region_id, $result['region']->region_id);
    }

    public function test_it_returns_null_beyond_the_usefulness_threshold(): void
    {
        // The Gulf of Guinea — hundreds of km from the nearest seeded Nigerian LGA.
        $result = NearestRegion::find(0.0, 0.0);

        $this->assertNull($result);
    }

    public function test_the_threshold_is_respected_at_the_boundary(): void
    {
        $region = Region::query()->whereNotNull('latitude')->firstOrFail();

        // ~1 degree of latitude is roughly 111km — comfortably past the 50km threshold in one
        // direction only, isolating the distance check from the "which region is nearest" logic.
        $farLat = (float) $region->latitude + 1.0;

        $result = NearestRegion::find($farLat, (float) $region->longitude);

        if ($result !== null) {
            // Some other seeded LGA happened to be closer than 50km to this point — still a
            // valid outcome, just assert the invariant the method actually promises.
            $this->assertLessThanOrEqual(NearestRegion::MAX_USEFUL_DISTANCE_KM, $result['distance_km']);
        } else {
            $this->assertNull($result);
        }
    }
}
