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
use App\Services\Scoring\RegionScoringService;
use App\Support\IndexCoverage;
use App\Support\IngestionWindow;
use Database\Seeders\AdditionalIndicesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T3 — Agriculture Stress. First index that needs new signal ingestion
 * (SOIL_MOISTURE, EVAPOTRANSPIRATION via Open-Meteo). Soil-water focused, distinct from the
 * rainfall + NDVI Drought Risk index.
 */
class AgricultureStressIndexTest extends TestCase
{
    use RefreshDatabase;

    private function index(): ScoringIndex
    {
        return ScoringIndex::query()->where('code', 'AGRICULTURE_STRESS')->firstOrFail();
    }

    public function test_the_index_and_its_new_signal_types_are_seeded(): void
    {
        $this->assertSame('Agriculture Stress Index', $this->index()->name);

        $this->assertSame(
            false,
            (bool) SignalType::query()->where('code', 'SOIL_MOISTURE')->value('higher_is_worse'),
        );
        $this->assertSame(
            true,
            (bool) SignalType::query()->where('code', 'EVAPOTRANSPIRATION')->value('higher_is_worse'),
        );
    }

    public function test_it_is_weighted_on_soil_moisture_rainfall_deficit_and_evapotranspiration(): void
    {
        $configs = RegionScoringConfig::query()
            ->where('index_id', $this->index()->index_id)
            ->whereNull('region_id')
            ->with('signalType')
            ->get()
            ->keyBy('signalType.code');

        $this->assertEqualsCanonicalizing(
            ['SOIL_MOISTURE', 'RAINFALL', 'EVAPOTRANSPIRATION'],
            $configs->keys()->all(),
        );
        $this->assertEquals(0.5, $configs['SOIL_MOISTURE']->weight);
        $this->assertEquals(0.3, $configs['RAINFALL']->weight);
        $this->assertEquals(0.2, $configs['EVAPOTRANSPIRATION']->weight);

        // Soil moisture and rainfall are inverted for this index — low is bad.
        $this->assertFalse((bool) $configs['SOIL_MOISTURE']->higher_is_worse);
        $this->assertFalse((bool) $configs['RAINFALL']->higher_is_worse);
        // Evapotranspiration keeps the signal default (higher demand = worse).
        $this->assertNull($configs['EVAPOTRANSPIRATION']->higher_is_worse);
    }

    public function test_it_has_a_recommended_action_for_every_risk_band(): void
    {
        $this->assertEqualsCanonicalizing(
            ['green', 'amber', 'red'],
            IndexActionRecommendation::query()->where('index_id', $this->index()->index_id)->pluck('risk_band')->all(),
        );
    }

    public function test_it_is_attached_to_the_agriculture_sector(): void
    {
        $this->assertEqualsCanonicalizing(
            ['AGRICULTURE'],
            $this->index()->sectors->pluck('code')->all(),
        );

        // Drought Risk stays in the sector alongside the new agriculture indices.
        $agriculture = Sector::query()->where('code', 'AGRICULTURE')->firstOrFail();
        $this->assertContains('DROUGHT_RISK', $agriculture->indices->pluck('code')->all());
        $this->assertContains('AGRICULTURE_STRESS', $agriculture->indices->pluck('code')->all());
    }

    public function test_an_agriculture_sector_follower_sees_it_on_their_dashboard(): void
    {
        $user = User::factory()->create();
        $agriculture = Sector::query()->where('code', 'AGRICULTURE')->firstOrFail();
        $user->sectorSubscriptions()->create(['sector_id' => $agriculture->sector_id]);

        $codes = IndexCoverage::resolve($user->fresh(), null)['available']->pluck('code');

        $this->assertTrue($codes->contains('AGRICULTURE_STRESS'));
    }

    public function test_the_scoring_engine_blends_the_three_signals_with_the_right_directions(): void
    {
        $region = Region::query()->firstOrFail();
        [$start, $end] = IngestionWindow::lastComplete();

        // SOIL_MOISTURE 0.225 in [0.05, 0.40] -> ratio .5 -> inverted -> 50
        // RAINFALL 50 in [0, 200] -> ratio .25 -> inverted -> 75
        // EVAPOTRANSPIRATION 30 in [0, 50] -> ratio .6 -> 60
        // weighted: .5*50 + .3*75 + .2*60 = 59.5
        foreach (['SOIL_MOISTURE' => 0.225, 'RAINFALL' => 50, 'EVAPOTRANSPIRATION' => 30] as $code => $value) {
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

        $this->assertSame(59.5, $result->score);
    }

    public function test_scores_calculate_picks_up_the_new_index_automatically(): void
    {
        $region = Region::query()->firstOrFail();
        $region->subscribers()->create(['user_id' => User::factory()->create()->id]);
        [$start, $end] = IngestionWindow::lastComplete();

        foreach (['SOIL_MOISTURE' => 0.1, 'RAINFALL' => 20, 'EVAPOTRANSPIRATION' => 40] as $code => $value) {
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
            ->whereHas('signalType', fn ($q) => $q->where('code', 'SOIL_MOISTURE'))
            ->update(['weight' => 0.7]);

        $this->seed(AdditionalIndicesSeeder::class);

        $this->assertSame(1, ScoringIndex::query()->where('code', 'AGRICULTURE_STRESS')->count());
        $this->assertSame(
            3,
            RegionScoringConfig::query()->where('index_id', $this->index()->index_id)->whereNull('region_id')->count(),
        );
        $this->assertEquals(
            0.7,
            RegionScoringConfig::query()
                ->where('index_id', $this->index()->index_id)
                ->whereNull('region_id')
                ->whereHas('signalType', fn ($q) => $q->where('code', 'SOIL_MOISTURE'))
                ->value('weight'),
        );
    }
}
