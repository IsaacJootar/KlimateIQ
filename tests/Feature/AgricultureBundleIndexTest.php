<?php

namespace Tests\Feature;

use App\Models\IndexActionRecommendation;
use App\Models\Region;
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
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T3 — the rest of the agriculture bundle: Irrigation Need and Rangeland Stress.
 * Both config-only now that SOIL_MOISTURE / EVAPOTRANSPIRATION ingestion is live; Rangeland
 * also reuses the existing VEGETATION signal.
 */
class AgricultureBundleIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    private function index(string $code): ScoringIndex
    {
        return ScoringIndex::query()->where('code', $code)->firstOrFail();
    }

    private function signal(Region $region, string $code, float $value, Carbon $start, Carbon $end): void
    {
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

    public function test_both_indices_are_seeded_and_attached_to_the_agriculture_sector(): void
    {
        $this->assertSame('Irrigation Need Index', $this->index('IRRIGATION_NEED')->name);
        $this->assertSame('Rangeland Stress Index', $this->index('RANGELAND_STRESS')->name);

        $agriculture = Sector::query()->where('code', 'AGRICULTURE')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['DROUGHT_RISK', 'AGRICULTURE_STRESS', 'IRRIGATION_NEED', 'RANGELAND_STRESS'],
            $agriculture->indices->pluck('code')->all(),
        );
    }

    public function test_each_index_has_an_action_for_every_risk_band(): void
    {
        foreach (['IRRIGATION_NEED', 'RANGELAND_STRESS'] as $code) {
            $this->assertEqualsCanonicalizing(
                ['green', 'amber', 'red'],
                IndexActionRecommendation::query()->where('index_id', $this->index($code)->index_id)->pluck('risk_band')->all(),
                "{$code} is missing a risk band",
            );
        }
    }

    public function test_an_agriculture_follower_sees_all_four_agriculture_indices(): void
    {
        $user = User::factory()->create();
        $user->sectorSubscriptions()->create([
            'sector_id' => Sector::query()->where('code', 'AGRICULTURE')->value('sector_id'),
        ]);

        $codes = IndexCoverage::resolve($user->fresh(), null)['available']->pluck('code');

        foreach (['DROUGHT_RISK', 'AGRICULTURE_STRESS', 'IRRIGATION_NEED', 'RANGELAND_STRESS'] as $code) {
            $this->assertTrue($codes->contains($code), "Agriculture follower should see {$code}");
        }
    }

    public function test_irrigation_need_leads_with_evapotranspiration_demand(): void
    {
        $configs = RegionScoringConfig::query()
            ->where('index_id', $this->index('IRRIGATION_NEED')->index_id)
            ->whereNull('region_id')
            ->with('signalType')
            ->get()
            ->keyBy('signalType.code');

        $this->assertEquals(0.5, $configs['EVAPOTRANSPIRATION']->weight);
        $this->assertEquals(0.3, $configs['SOIL_MOISTURE']->weight);
        $this->assertEquals(0.2, $configs['RAINFALL']->weight);
        $this->assertFalse((bool) $configs['SOIL_MOISTURE']->higher_is_worse);
        $this->assertFalse((bool) $configs['RAINFALL']->higher_is_worse);

        $region = Region::query()->firstOrFail();
        [$start, $end] = IngestionWindow::lastComplete();
        // ET0 40/50 -> 80 ; SOIL_MOISTURE 0.225 -> inv 50 ; RAINFALL 40/200 -> inv 80
        // .5*80 + .3*50 + .2*80 = 71
        $this->signal($region, 'EVAPOTRANSPIRATION', 40, $start, $end);
        $this->signal($region, 'SOIL_MOISTURE', 0.225, $start, $end);
        $this->signal($region, 'RAINFALL', 40, $start, $end);

        $result = app(RegionScoringService::class)->calculate($this->index('IRRIGATION_NEED'), $region, $start, $end);

        $this->assertSame(71.0, $result->score);
    }

    public function test_rangeland_stress_blends_inverse_ndvi_and_rainfall_deficit(): void
    {
        $configs = RegionScoringConfig::query()
            ->where('index_id', $this->index('RANGELAND_STRESS')->index_id)
            ->whereNull('region_id')
            ->with('signalType')
            ->get()
            ->keyBy('signalType.code');

        $this->assertEqualsCanonicalizing(['VEGETATION', 'RAINFALL'], $configs->keys()->all());
        $this->assertFalse((bool) $configs['VEGETATION']->higher_is_worse);
        $this->assertFalse((bool) $configs['RAINFALL']->higher_is_worse);

        $region = Region::query()->firstOrFail();
        [$start, $end] = IngestionWindow::lastComplete();
        // VEGETATION 0.2 in [-1,1] -> ratio .6 -> inv 40 ; RAINFALL 50/200 -> inv 75
        // .6*40 + .4*75 = 54
        $this->signal($region, 'VEGETATION', 0.2, $start, $end);
        $this->signal($region, 'RAINFALL', 50, $start, $end);

        $result = app(RegionScoringService::class)->calculate($this->index('RANGELAND_STRESS'), $region, $start, $end);

        $this->assertSame(54.0, $result->score);
    }

    public function test_the_seeder_is_idempotent_and_never_overwrites_a_tuned_weight(): void
    {
        RegionScoringConfig::query()
            ->where('index_id', $this->index('RANGELAND_STRESS')->index_id)
            ->whereNull('region_id')
            ->whereHas('signalType', fn ($q) => $q->where('code', 'VEGETATION'))
            ->update(['weight' => 0.9]);

        $this->seed(AdditionalIndicesSeeder::class);

        $this->assertSame(1, ScoringIndex::query()->where('code', 'IRRIGATION_NEED')->count());
        $this->assertSame(1, ScoringIndex::query()->where('code', 'RANGELAND_STRESS')->count());
        $this->assertEquals(
            0.9,
            RegionScoringConfig::query()
                ->where('index_id', $this->index('RANGELAND_STRESS')->index_id)
                ->whereNull('region_id')
                ->whereHas('signalType', fn ($q) => $q->where('code', 'VEGETATION'))
                ->value('weight'),
        );
    }
}
