<?php

namespace Tests\Feature;

use App\Models\IndexActionRecommendation;
use App\Models\Region;
use App\Models\RegionScore;
use App\Models\RegionScoringConfig;
use App\Models\RegionSignal;
use App\Models\ScoringIndex;
use App\Models\Sector;
use App\Models\SignalType;
use App\Models\User;
use App\Services\Ingestion\SoilTemperatureIngestionService;
use App\Services\Scoring\RegionScoringService;
use App\Support\IndexCoverage;
use App\Support\IngestionCadence;
use App\Support\IngestionWindow;
use Database\Seeders\AdditionalIndicesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T3 — Planting Window Risk. New signal (SOIL_TEMPERATURE) + a new index, distinct
 * from Agriculture Stress: this one is about planting *timing*, not ongoing crop water stress.
 */
class PlantingWindowRiskIndexTest extends TestCase
{
    use RefreshDatabase;

    private function index(): ScoringIndex
    {
        return ScoringIndex::query()->where('code', 'PLANTING_WINDOW_RISK')->firstOrFail();
    }

    public function test_the_index_is_seeded(): void
    {
        $this->assertSame('Planting Window Risk Index', $this->index()->name);
    }

    public function test_soil_temperature_is_seeded_on_the_daily_cadence(): void
    {
        $this->assertTrue((bool) SignalType::query()->where('code', 'SOIL_TEMPERATURE')->value('higher_is_worse'));
        $this->assertContains('SOIL_TEMPERATURE', IngestionCadence::DAILY);
    }

    public function test_it_is_weighted_on_soil_temperature_rainfall_and_soil_moisture(): void
    {
        $configs = RegionScoringConfig::query()
            ->where('index_id', $this->index()->index_id)
            ->whereNull('region_id')
            ->with('signalType')
            ->get();

        $this->assertEqualsCanonicalizing(
            ['SOIL_TEMPERATURE', 'RAINFALL', 'SOIL_MOISTURE'],
            $configs->pluck('signalType.code')->all(),
        );
        $this->assertEquals(0.4, $configs->firstWhere('signalType.code', 'SOIL_TEMPERATURE')->weight);
        $this->assertEquals(0.3, $configs->firstWhere('signalType.code', 'RAINFALL')->weight);
        $this->assertEquals(0.3, $configs->firstWhere('signalType.code', 'SOIL_MOISTURE')->weight);
        $this->assertFalse((bool) $configs->firstWhere('signalType.code', 'RAINFALL')->higher_is_worse);
        $this->assertFalse((bool) $configs->firstWhere('signalType.code', 'SOIL_MOISTURE')->higher_is_worse);
    }

    public function test_soil_temperature_ingestion_averages_hourly_readings(): void
    {
        $region = Region::query()->first();
        $region->update(['latitude' => 6.6, 'longitude' => 3.35]);

        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response([
                'hourly' => ['soil_temperature_0_to_7cm' => [28.0, 32.0]],
            ], 200),
        ]);

        $signal = app(SoilTemperatureIngestionService::class)->ingestForRegion($region->fresh(), Carbon::parse('2026-08-03'), Carbon::parse('2026-08-09'));

        $this->assertEquals(30.0, $signal->value);
        $this->assertSame('SOIL_TEMPERATURE', $signal->signalType->code);
    }

    public function test_it_has_a_recommended_action_for_every_risk_band(): void
    {
        $bands = IndexActionRecommendation::query()->where('index_id', $this->index()->index_id)->pluck('risk_band');

        $this->assertEqualsCanonicalizing(['green', 'amber', 'red'], $bands->all());
    }

    public function test_it_is_attached_to_the_agriculture_sector(): void
    {
        $this->assertEqualsCanonicalizing(['AGRICULTURE'], $this->index()->sectors->pluck('code')->all());
    }

    public function test_an_agriculture_sector_follower_sees_it_on_their_dashboard(): void
    {
        $user = User::factory()->create();
        $agriculture = Sector::query()->where('code', 'AGRICULTURE')->firstOrFail();
        $user->sectorSubscriptions()->create(['sector_id' => $agriculture->sector_id]);

        $codes = IndexCoverage::resolve($user->fresh(), null)['available']->pluck('code');

        $this->assertTrue($codes->contains('PLANTING_WINDOW_RISK'));
    }

    public function test_the_scoring_engine_produces_a_score_from_the_three_signals(): void
    {
        $region = Region::query()->firstOrFail();
        [$start, $end] = IngestionWindow::lastComplete();

        // SOIL_TEMPERATURE 32.5/[20,45] -> (32.5-20)/25 = 50 ; RAINFALL 100/200 inverted -> 50
        // SOIL_MOISTURE 0.225/[0.05,0.40] inverted -> 50
        // .4*50 + .3*50 + .3*50 = 50
        foreach (['SOIL_TEMPERATURE' => 32.5, 'RAINFALL' => 100, 'SOIL_MOISTURE' => 0.225] as $code => $value) {
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

        $this->assertSame(50.0, $result->score);
    }

    public function test_scores_calculate_picks_up_the_new_index_automatically(): void
    {
        $region = Region::query()->firstOrFail();
        [$start, $end] = IngestionWindow::lastComplete();

        foreach (['SOIL_TEMPERATURE' => 40, 'RAINFALL' => 20, 'SOIL_MOISTURE' => 0.08] as $code => $value) {
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

        $this->artisan('scores:calculate', ['--region' => $region->region_id])->assertSuccessful();

        $this->assertNotNull(
            RegionScore::query()
                ->where('index_id', $this->index()->index_id)
                ->where('region_id', $region->region_id)
                ->where('period_start', $start->toDateString())
                ->value('score'),
        );
    }

    public function test_the_seeder_is_idempotent_and_never_overwrites_a_tuned_weight(): void
    {
        RegionScoringConfig::query()
            ->where('index_id', $this->index()->index_id)
            ->whereNull('region_id')
            ->whereHas('signalType', fn ($q) => $q->where('code', 'SOIL_TEMPERATURE'))
            ->update(['weight' => 0.6]);

        $this->seed(AdditionalIndicesSeeder::class);

        $this->assertSame(1, ScoringIndex::query()->where('code', 'PLANTING_WINDOW_RISK')->count());
        $this->assertEquals(
            0.6,
            RegionScoringConfig::query()
                ->where('index_id', $this->index()->index_id)
                ->whereNull('region_id')
                ->whereHas('signalType', fn ($q) => $q->where('code', 'SOIL_TEMPERATURE'))
                ->value('weight'),
        );
    }
}
