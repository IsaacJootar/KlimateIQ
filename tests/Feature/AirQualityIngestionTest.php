<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionScoringConfig;
use App\Models\RegionSignal;
use App\Models\ScoringIndex;
use App\Services\Ingestion\AirQualityPm10IngestionService;
use App\Services\Ingestion\AirQualityPm25IngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AirQualityIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function regionWithCoordinates(): Region
    {
        $region = Region::query()->first();
        $region->update(['latitude' => 9.0765, 'longitude' => 7.3986]);

        return $region->fresh();
    }

    public function test_pm25_ingestion_averages_hourly_readings_over_the_period(): void
    {
        Http::fake([
            'air-quality-api.open-meteo.com/*' => Http::response([
                'hourly' => ['pm2_5' => [10.0, 20.0, 30.0]],
            ], 200),
        ]);

        $signal = app(AirQualityPm25IngestionService::class)->ingestForRegion($this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'));

        $this->assertNotNull($signal);
        $this->assertEquals(20.0, $signal->value);
        $this->assertSame('AIR_QUALITY_PM25', $signal->signalType->code);
        $this->assertSame('Open-Meteo Air Quality API (CAMS)', $signal->source);
    }

    public function test_pm10_ingestion_averages_hourly_readings_over_the_period(): void
    {
        Http::fake([
            'air-quality-api.open-meteo.com/*' => Http::response([
                'hourly' => ['pm10' => [40.0, 60.0]],
            ], 200),
        ]);

        $signal = app(AirQualityPm10IngestionService::class)->ingestForRegion($this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'));

        $this->assertNotNull($signal);
        $this->assertEquals(50.0, $signal->value);
        $this->assertSame('AIR_QUALITY_PM10', $signal->signalType->code);
    }

    public function test_it_returns_null_rather_than_zero_when_the_api_has_no_data(): void
    {
        Http::fake(['air-quality-api.open-meteo.com/*' => Http::response(['hourly' => ['pm2_5' => []]], 200)]);

        $signal = app(AirQualityPm25IngestionService::class)->ingestForRegion($this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'));

        $this->assertNull($signal);
        $this->assertSame(0, RegionSignal::count());
    }

    public function test_it_returns_null_when_the_request_fails(): void
    {
        Http::fake(['air-quality-api.open-meteo.com/*' => Http::response([], 500)]);

        $signal = app(AirQualityPm25IngestionService::class)->ingestForRegion($this->regionWithCoordinates(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'));

        $this->assertNull($signal);
    }

    public function test_both_are_registered_as_configured_ingestion_sources(): void
    {
        $this->assertContains(AirQualityPm25IngestionService::class, config('ingestion.sources'));
        $this->assertContains(AirQualityPm10IngestionService::class, config('ingestion.sources'));
    }

    public function test_respiratory_risk_index_and_its_scoring_configs_are_seeded(): void
    {
        $index = ScoringIndex::where('code', 'RESPIRATORY_RISK')->first();
        $this->assertNotNull($index);

        // PM2.5 + PM10 + the depth pass (ozone, NO2, dust) — see AdditionalIndicesSeeder.
        $configs = RegionScoringConfig::where('index_id', $index->index_id)
            ->whereNull('region_id')
            ->with('signalType')
            ->get()
            ->keyBy('signalType.code');

        $this->assertEqualsCanonicalizing(
            ['AIR_QUALITY_PM25', 'AIR_QUALITY_PM10', 'OZONE', 'NO2', 'DUST'],
            $configs->keys()->all(),
        );
        $this->assertEquals(0.4, $configs['AIR_QUALITY_PM25']->weight);
        $this->assertEquals(0.2, $configs['AIR_QUALITY_PM10']->weight);
        $this->assertEqualsWithDelta(1.0, $configs->sum('weight'), 0.0001);
    }
}
