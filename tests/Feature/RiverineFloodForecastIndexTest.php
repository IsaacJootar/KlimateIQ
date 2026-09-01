<?php

namespace Tests\Feature;

use App\Models\IndexActionRecommendation;
use App\Models\RegionScoringConfig;
use App\Models\ScoringIndex;
use Database\Seeders\AdditionalIndicesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T4 M3 — the first forecast index.
 */
class RiverineFloodForecastIndexTest extends TestCase
{
    use RefreshDatabase;

    private function index(): ScoringIndex
    {
        return ScoringIndex::query()->where('code', 'RIVERINE_FLOOD_FORECAST')->firstOrFail();
    }

    public function test_it_is_seeded_and_marked_as_a_forecast_index(): void
    {
        $this->assertSame('Riverine Flood Forecast', $this->index()->name);
        $this->assertTrue($this->index()->is_forecast);
        $this->assertTrue(ScoringIndex::query()->forecast()->pluck('code')->contains('RIVERINE_FLOOD_FORECAST'));
        $this->assertFalse(ScoringIndex::query()->observed()->pluck('code')->contains('RIVERINE_FLOOD_FORECAST'));
    }

    public function test_it_is_weighted_on_river_discharge_alone(): void
    {
        $configs = RegionScoringConfig::query()
            ->where('index_id', $this->index()->index_id)->whereNull('region_id')->with('signalType')->get();

        $this->assertSame(['RIVER_DISCHARGE'], $configs->pluck('signalType.code')->all());
        $this->assertEquals(1.0, $configs->first()->weight);
    }

    public function test_it_has_an_action_for_every_band_and_sits_in_the_water_and_emergency_sectors(): void
    {
        $this->assertEqualsCanonicalizing(
            ['green', 'amber', 'red'],
            IndexActionRecommendation::query()->where('index_id', $this->index()->index_id)->pluck('risk_band')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['WATER_SANITATION', 'EMERGENCY_RESPONSE'],
            $this->index()->sectors->pluck('code')->all(),
        );
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(AdditionalIndicesSeeder::class);

        $this->assertSame(1, ScoringIndex::query()->where('code', 'RIVERINE_FLOOD_FORECAST')->count());
        $this->assertSame(1, RegionScoringConfig::query()->where('index_id', $this->index()->index_id)->whereNull('region_id')->count());
    }
}
