<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionSignal;
use App\Services\Ingestion\PopulationExposureIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PopulationExposureIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_signal_from_the_regions_own_population_column(): void
    {
        $region = Region::query()->first();
        $region->update(['population' => 123456]);

        $periodStart = Carbon::parse('2026-08-03');
        $periodEnd = Carbon::parse('2026-08-09');

        $signal = app(PopulationExposureIngestionService::class)->ingestForRegion($region, $periodStart, $periodEnd);

        $this->assertNotNull($signal);
        $this->assertEquals(123456, $signal->value);
        $this->assertSame('POPULATION_EXPOSURE', $signal->signalType->code);
    }

    public function test_it_returns_null_when_the_region_has_no_population(): void
    {
        $region = Region::query()->first();
        $region->update(['population' => null]);

        $signal = app(PopulationExposureIngestionService::class)->ingestForRegion($region, Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'));

        $this->assertNull($signal);
        $this->assertSame(0, RegionSignal::where('region_id', $region->region_id)->count());
    }

    public function test_it_is_registered_as_a_configured_ingestion_source(): void
    {
        $this->assertContains(PopulationExposureIngestionService::class, config('ingestion.sources'));
    }

    public function test_re_ingesting_the_same_period_updates_rather_than_duplicates(): void
    {
        $region = Region::query()->first();
        $region->update(['population' => 100000]);
        $periodStart = Carbon::parse('2026-08-03');
        $periodEnd = Carbon::parse('2026-08-09');
        $service = app(PopulationExposureIngestionService::class);

        $service->ingestForRegion($region, $periodStart, $periodEnd);
        $region->update(['population' => 150000]);
        $service->ingestForRegion($region, $periodStart, $periodEnd);

        $this->assertSame(1, RegionSignal::where('region_id', $region->region_id)->count());
        $this->assertEquals(150000, RegionSignal::where('region_id', $region->region_id)->first()->value);
    }
}
