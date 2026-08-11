<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionSignal;
use App\Services\Ingestion\RainfallIngestionService;
use App\Services\Ingestion\TemperatureIngestionService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenMeteoFallbackTest extends TestCase
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

    public function test_rainfall_uses_nasa_power_when_it_succeeds_and_never_calls_open_meteo(): void
    {
        Http::fake([
            'power.larc.nasa.gov/*' => Http::response([
                'properties' => ['parameter' => ['PRECTOTCORR' => ['2026-08-03' => 10.0, '2026-08-04' => 5.0]]],
            ], 200),
            'archive-api.open-meteo.com/*' => Http::response([], 500),
        ]);

        $signal = app(RainfallIngestionService::class)->ingestForRegion($this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'));

        $this->assertNotNull($signal);
        $this->assertSame('NASA POWER (PRECTOTCORR)', $signal->source);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'open-meteo.com'));
    }

    public function test_rainfall_falls_back_to_open_meteo_when_nasa_power_fails(): void
    {
        Http::fake([
            'power.larc.nasa.gov/*' => Http::response([], 500),
            'archive-api.open-meteo.com/*' => Http::response([
                'daily' => ['precipitation_sum' => [12.5, 17.8, 10.1]],
            ], 200),
        ]);

        $signal = app(RainfallIngestionService::class)->ingestForRegion($this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'));

        $this->assertNotNull($signal);
        $this->assertEquals(40.4, $signal->value);
        $this->assertSame('Open-Meteo (fallback — NASA POWER unavailable)', $signal->source);
    }

    public function test_rainfall_throws_when_both_sources_fail(): void
    {
        Http::fake([
            'power.larc.nasa.gov/*' => Http::response([], 500),
            'archive-api.open-meteo.com/*' => Http::response([], 500),
        ]);

        $this->expectException(\RuntimeException::class);

        app(RainfallIngestionService::class)->ingestForRegion($this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'));
    }

    public function test_temperature_falls_back_to_open_meteo_when_nasa_power_returns_no_usable_data(): void
    {
        Http::fake([
            'power.larc.nasa.gov/*' => Http::response([
                'properties' => ['parameter' => ['T2M' => ['2026-08-03' => -999.0]]],
            ], 200),
            'archive-api.open-meteo.com/*' => Http::response([
                'daily' => ['temperature_2m_mean' => [24.3, 23.3, 22.6]],
            ], 200),
        ]);

        $signal = app(TemperatureIngestionService::class)->ingestForRegion($this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'));

        $this->assertNotNull($signal);
        $this->assertEqualsWithDelta(23.4, $signal->value, 0.01);
        $this->assertSame('Open-Meteo (fallback — NASA POWER unavailable)', $signal->source);
    }

    public function test_temperature_returns_null_when_both_sources_have_no_data_but_nasa_power_did_not_error(): void
    {
        Http::fake([
            'power.larc.nasa.gov/*' => Http::response([
                'properties' => ['parameter' => ['T2M' => []]],
            ], 200),
            'archive-api.open-meteo.com/*' => Http::response(['daily' => ['temperature_2m_mean' => []]], 200),
        ]);

        $signal = app(TemperatureIngestionService::class)->ingestForRegion($this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'));

        $this->assertNull($signal);
        $this->assertSame(0, RegionSignal::count());
    }
}
