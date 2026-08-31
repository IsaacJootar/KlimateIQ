<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionSignal;
use App\Services\Ingestion\DustIngestionService;
use App\Services\Ingestion\HumidityIngestionService;
use App\Services\Ingestion\WindIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The three Open-Meteo signals behind Wildfire Risk and Dust Storm Risk (BUILD_PLAN.md T3).
 */
class FireAndDustSignalsIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function regionWithCoordinates(): Region
    {
        $region = Region::query()->first();
        $region->update(['latitude' => 12.0022, 'longitude' => 8.5920]);

        return $region->fresh();
    }

    public function test_humidity_averages_hourly_readings(): void
    {
        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response([
                'hourly' => ['relative_humidity_2m' => [40.0, 50.0, 60.0]],
            ], 200),
        ]);

        $signal = app(HumidityIngestionService::class)->ingestForRegion(
            $this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'),
        );

        $this->assertNotNull($signal);
        $this->assertEquals(50.0, $signal->value);
        $this->assertSame('HUMIDITY', $signal->signalType->code);
    }

    public function test_wind_averages_the_daily_maxima(): void
    {
        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response([
                'daily' => ['wind_speed_10m_max' => [20.0, 30.0, 40.0]],
            ], 200),
        ]);

        $signal = app(WindIngestionService::class)->ingestForRegion(
            $this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'),
        );

        $this->assertNotNull($signal);
        $this->assertEquals(30.0, $signal->value);
        $this->assertSame('WIND_SPEED', $signal->signalType->code);
    }

    public function test_dust_averages_hourly_readings_from_the_air_quality_api(): void
    {
        Http::fake([
            'air-quality-api.open-meteo.com/*' => Http::response([
                'hourly' => ['dust' => [100.0, 200.0, 300.0]],
            ], 200),
        ]);

        $signal = app(DustIngestionService::class)->ingestForRegion(
            $this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'),
        );

        $this->assertNotNull($signal);
        $this->assertEquals(200.0, $signal->value);
        $this->assertSame('DUST', $signal->signalType->code);
        $this->assertStringContainsString('CAMS', $signal->source);
    }

    public function test_they_return_null_rather_than_zero_on_an_empty_api_response(): void
    {
        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response(['hourly' => ['relative_humidity_2m' => []]], 200),
        ]);

        $this->assertNull(app(HumidityIngestionService::class)->ingestForRegion(
            $this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'),
        ));
        $this->assertSame(0, RegionSignal::count());
    }

    public function test_all_three_are_registered_ingestion_sources(): void
    {
        $sources = config('ingestion.sources');

        $this->assertContains(HumidityIngestionService::class, $sources);
        $this->assertContains(WindIngestionService::class, $sources);
        $this->assertContains(DustIngestionService::class, $sources);
    }
}
