<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionSignal;
use App\Services\Ingestion\EvapotranspirationIngestionService;
use App\Services\Ingestion\SoilMoistureIngestionService;
use App\Support\IngestionCadence;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The two Open-Meteo signals behind the Agriculture Stress index (BUILD_PLAN.md T3).
 */
class AgricultureSignalsIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    private function regionWithCoordinates(): Region
    {
        $region = Region::query()->first();
        $region->update(['latitude' => 9.0765, 'longitude' => 7.3986]);

        return $region->fresh();
    }

    public function test_soil_moisture_ingestion_averages_hourly_readings_over_the_period(): void
    {
        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response([
                'hourly' => ['soil_moisture_7_to_28cm' => [0.20, 0.30, 0.40]],
            ], 200),
        ]);

        $signal = app(SoilMoistureIngestionService::class)->ingestForRegion(
            $this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'),
        );

        $this->assertNotNull($signal);
        $this->assertEquals(0.30, $signal->value);
        $this->assertSame('SOIL_MOISTURE', $signal->signalType->code);
        $this->assertStringContainsString('ERA5-Land', $signal->source);
    }

    public function test_evapotranspiration_ingestion_sums_daily_millimetres_over_the_period(): void
    {
        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response([
                'daily' => ['et0_fao_evapotranspiration' => [3.0, 4.0, 5.0]],
            ], 200),
        ]);

        $signal = app(EvapotranspirationIngestionService::class)->ingestForRegion(
            $this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'),
        );

        $this->assertNotNull($signal);
        $this->assertEquals(12.0, $signal->value);
        $this->assertSame('EVAPOTRANSPIRATION', $signal->signalType->code);
    }

    public function test_they_return_null_rather_than_zero_when_the_api_has_no_data(): void
    {
        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response(['hourly' => ['soil_moisture_7_to_28cm' => []]], 200),
        ]);

        $signal = app(SoilMoistureIngestionService::class)->ingestForRegion(
            $this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'),
        );

        $this->assertNull($signal);
        $this->assertSame(0, RegionSignal::count());
    }

    public function test_they_return_null_when_the_request_fails(): void
    {
        Http::fake(['archive-api.open-meteo.com/*' => Http::response([], 500)]);

        $this->assertNull(
            app(EvapotranspirationIngestionService::class)->ingestForRegion(
                $this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'),
            ),
        );
    }

    public function test_both_are_registered_as_configured_ingestion_sources_on_the_daily_cadence(): void
    {
        $this->assertContains(SoilMoistureIngestionService::class, config('ingestion.sources'));
        $this->assertContains(EvapotranspirationIngestionService::class, config('ingestion.sources'));

        $this->assertContains('SOIL_MOISTURE', IngestionCadence::DAILY);
        $this->assertContains('EVAPOTRANSPIRATION', IngestionCadence::DAILY);
    }
}
