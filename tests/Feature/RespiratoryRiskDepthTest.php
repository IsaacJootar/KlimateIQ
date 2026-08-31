<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionScoringConfig;
use App\Models\RegionSignal;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Services\Ingestion\AirQualityNo2IngestionService;
use App\Services\Ingestion\AirQualityOzoneIngestionService;
use App\Services\Scoring\RegionScoringService;
use App\Support\IngestionCadence;
use App\Support\IngestionWindow;
use Database\Seeders\AdditionalIndicesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T3 — the Respiratory Risk depth pass. Ground-level ozone and NO2 (two new
 * Open-Meteo Air Quality signals) plus CAMS dust folded into the index, PM weights rebalanced
 * down to make room.
 */
class RespiratoryRiskDepthTest extends TestCase
{
    use RefreshDatabase;

    private function index(): ScoringIndex
    {
        return ScoringIndex::query()->where('code', 'RESPIRATORY_RISK')->firstOrFail();
    }

    private function config(string $signalCode): RegionScoringConfig
    {
        return RegionScoringConfig::query()
            ->where('index_id', $this->index()->index_id)
            ->whereNull('region_id')
            ->whereHas('signalType', fn ($q) => $q->where('code', $signalCode))
            ->firstOrFail();
    }

    public function test_the_new_gaseous_signal_types_are_seeded_on_the_daily_cadence(): void
    {
        foreach (['OZONE', 'NO2'] as $code) {
            $this->assertTrue((bool) SignalType::query()->where('code', $code)->value('higher_is_worse'));
            $this->assertContains($code, IngestionCadence::DAILY);
        }
    }

    public function test_the_index_now_blends_five_pollutants_summing_to_one(): void
    {
        $configs = RegionScoringConfig::query()
            ->where('index_id', $this->index()->index_id)
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
        $this->assertEquals(0.15, $configs['OZONE']->weight);
        $this->assertEquals(0.1, $configs['NO2']->weight);
        $this->assertEquals(0.15, $configs['DUST']->weight);
        $this->assertEqualsWithDelta(1.0, $configs->sum('weight'), 0.0001);
    }

    public function test_ozone_and_no2_ingestion_average_hourly_readings(): void
    {
        $region = Region::query()->first();
        $region->update(['latitude' => 6.6, 'longitude' => 3.35]);

        Http::fake([
            'air-quality-api.open-meteo.com/*' => Http::response([
                'hourly' => ['ozone' => [40.0, 60.0], 'nitrogen_dioxide' => [8.0, 12.0]],
            ], 200),
        ]);

        $ozone = app(AirQualityOzoneIngestionService::class)->ingestForRegion($region->fresh(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'));
        $no2 = app(AirQualityNo2IngestionService::class)->ingestForRegion($region->fresh(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'));

        $this->assertEquals(50.0, $ozone->value);
        $this->assertSame('OZONE', $ozone->signalType->code);
        $this->assertEquals(10.0, $no2->value);
        $this->assertSame('NO2', $no2->signalType->code);
    }

    public function test_the_scoring_engine_blends_all_five(): void
    {
        $region = Region::query()->firstOrFail();
        [$start, $end] = IngestionWindow::lastComplete();

        // PM2.5 200.16/500.4 -> 40 ; PM10 302/604 -> 50 ; OZONE 150/300 -> 50 ; NO2 50/200 -> 25 ; DUST 100/500 -> 20
        // .4*40 + .2*50 + .15*50 + .1*25 + .15*20 = 39
        $values = [
            'AIR_QUALITY_PM25' => 200.16,
            'AIR_QUALITY_PM10' => 302,
            'OZONE' => 150,
            'NO2' => 50,
            'DUST' => 100,
        ];

        foreach ($values as $code => $value) {
            RegionSignal::query()->create([
                'region_id' => $region->region_id,
                'signal_type_id' => SignalType::query()->where('code', $code)->value('signal_type_id'),
                'period_start' => $start,
                'period_end' => $end,
                'value' => $value,
                'source' => 'test',
                'ingested_at' => now(),
            ]);
        }

        $result = app(RegionScoringService::class)->calculate($this->index(), $region, $start, $end);

        $this->assertSame(39.0, $result->score);
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(AdditionalIndicesSeeder::class);

        $this->assertSame(0.4, (float) $this->config('AIR_QUALITY_PM25')->weight);
        $this->assertSame(
            5,
            RegionScoringConfig::query()->where('index_id', $this->index()->index_id)->whereNull('region_id')->count(),
        );
    }

    public function test_it_never_overrides_an_admin_tuned_pm_weight(): void
    {
        // Admin sets PM2.5 to something other than the rebalanced 0.4.
        $this->config('AIR_QUALITY_PM25')->update(['weight' => 0.55]);

        $this->seed(AdditionalIndicesSeeder::class);

        $this->assertSame(0.55, (float) $this->config('AIR_QUALITY_PM25')->fresh()->weight);
    }
}
