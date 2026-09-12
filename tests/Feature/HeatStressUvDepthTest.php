<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionScoringConfig;
use App\Models\RegionSignal;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Services\Ingestion\UvIndexIngestionService;
use App\Services\Scoring\RegionScoringService;
use App\Support\IngestionCadence;
use App\Support\IngestionWindow;
use Database\Seeders\AdditionalIndicesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T3 (UV_INDEX) — a Heat Stress Risk depth pass rather than a standalone index:
 * the "occupational / skin-eye advisories" use case tracks the same outdoor-exposure timing
 * temperature already does.
 */
class HeatStressUvDepthTest extends TestCase
{
    use RefreshDatabase;

    private function index(): ScoringIndex
    {
        return ScoringIndex::query()->where('code', 'HEAT_STRESS_RISK')->firstOrFail();
    }

    private function config(string $signalCode): RegionScoringConfig
    {
        return RegionScoringConfig::query()
            ->where('index_id', $this->index()->index_id)
            ->whereNull('region_id')
            ->whereHas('signalType', fn ($q) => $q->where('code', $signalCode))
            ->firstOrFail();
    }

    public function test_uv_index_is_seeded_on_the_daily_cadence(): void
    {
        $this->assertTrue((bool) SignalType::query()->where('code', 'UV_INDEX')->value('higher_is_worse'));
        $this->assertContains('UV_INDEX', IngestionCadence::DAILY);
    }

    public function test_the_index_now_blends_temperature_vegetation_and_uv_summing_to_one(): void
    {
        $configs = RegionScoringConfig::query()
            ->where('index_id', $this->index()->index_id)
            ->whereNull('region_id')
            ->with('signalType')
            ->get()
            ->keyBy('signalType.code');

        $this->assertEqualsCanonicalizing(['TEMPERATURE', 'VEGETATION', 'UV_INDEX'], $configs->keys()->all());
        $this->assertEquals(0.6, $configs['TEMPERATURE']->weight);
        $this->assertEquals(0.25, $configs['VEGETATION']->weight);
        $this->assertEquals(0.15, $configs['UV_INDEX']->weight);
        $this->assertEqualsWithDelta(1.0, $configs->sum('weight'), 0.0001);
    }

    public function test_uv_ingestion_averages_daily_maxima(): void
    {
        $region = Region::query()->first();
        $region->update(['latitude' => 6.6, 'longitude' => 3.35]);

        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response([
                'daily' => ['uv_index_max' => [8.0, 10.0]],
            ], 200),
        ]);

        $signal = app(UvIndexIngestionService::class)->ingestForRegion($region->fresh(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'));

        $this->assertEquals(9.0, $signal->value);
        $this->assertSame('UV_INDEX', $signal->signalType->code);
    }

    public function test_the_scoring_engine_blends_all_three(): void
    {
        $region = Region::query()->firstOrFail();
        [$start, $end] = IngestionWindow::lastComplete();

        // TEMPERATURE 30/45 (bound 15-45) -> (30-15)/30 = 50 ; VEGETATION 0.2 of [-1,1] inverted -> 40
        // UV_INDEX 8.8/11 -> 80
        // .6*50 + .25*40 + .15*80 = 52
        RegionSignal::query()->create([
            'region_id' => $region->region_id,
            'signal_type_id' => SignalType::query()->where('code', 'TEMPERATURE')->value('signal_type_id'),
            'period_start' => $start, 'period_end' => $end, 'value' => 30, 'source' => 'test', 'ingested_at' => now(),
        ]);
        RegionSignal::query()->create([
            'region_id' => $region->region_id,
            'signal_type_id' => SignalType::query()->where('code', 'VEGETATION')->value('signal_type_id'),
            'period_start' => $start, 'period_end' => $end, 'value' => 0.2, 'source' => 'test', 'ingested_at' => now(),
        ]);
        RegionSignal::query()->create([
            'region_id' => $region->region_id,
            'signal_type_id' => SignalType::query()->where('code', 'UV_INDEX')->value('signal_type_id'),
            'period_start' => $start, 'period_end' => $end, 'value' => 8.8, 'source' => 'test', 'ingested_at' => now(),
        ]);

        $result = app(RegionScoringService::class)->calculate($this->index(), $region, $start, $end);

        $this->assertSame(52.0, $result->score);
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(AdditionalIndicesSeeder::class);

        $this->assertSame(0.6, (float) $this->config('TEMPERATURE')->weight);
        $this->assertSame(
            3,
            RegionScoringConfig::query()->where('index_id', $this->index()->index_id)->whereNull('region_id')->count(),
        );
    }

    public function test_it_never_overrides_an_admin_tuned_temperature_weight(): void
    {
        // Admin sets TEMPERATURE to something other than either rebalanced default (0.7 or 0.6).
        $this->config('TEMPERATURE')->update(['weight' => 0.5]);

        $this->seed(AdditionalIndicesSeeder::class);

        $this->assertSame(0.5, (float) $this->config('TEMPERATURE')->fresh()->weight);
    }
}
