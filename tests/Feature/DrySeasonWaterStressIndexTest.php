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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clarity Pass E1 — Dry-Season Water Stress. A config-only index: rainfall, JRC surface water,
 * soil moisture and ET₀ are all already ingested for other indices, no new ingestion. The twist
 * is direction — STANDING_WATER and RAINFALL default to "higher is worse" (flood framing); here
 * less water available is worse.
 */
class DrySeasonWaterStressIndexTest extends TestCase
{
    use RefreshDatabase;

    private function index(): ScoringIndex
    {
        return ScoringIndex::query()->where('code', 'DRY_SEASON_WATER_STRESS')->firstOrFail();
    }

    public function test_the_index_is_seeded(): void
    {
        $this->assertSame('Dry-Season Water Stress Index', $this->index()->name);
    }

    public function test_it_is_weighted_on_the_four_water_balance_signals_with_inverted_directions(): void
    {
        $configs = RegionScoringConfig::query()
            ->where('index_id', $this->index()->index_id)
            ->whereNull('region_id')
            ->with('signalType')
            ->get()
            ->keyBy('signalType.code');

        $this->assertEqualsCanonicalizing(
            ['RAINFALL', 'STANDING_WATER', 'SOIL_MOISTURE', 'EVAPOTRANSPIRATION'],
            $configs->keys()->all(),
        );

        // Less rain / less surface water / drier soil = more stress: direction flipped.
        $this->assertFalse($configs['RAINFALL']->higher_is_worse);
        $this->assertFalse($configs['STANDING_WATER']->higher_is_worse);
        $this->assertFalse($configs['SOIL_MOISTURE']->higher_is_worse);
        // ET₀ keeps the signal default (more demand = worse).
        $this->assertNull($configs['EVAPOTRANSPIRATION']->higher_is_worse);

        $this->assertEqualsWithDelta(1.0, $configs->sum('weight'), 0.001);
    }

    public function test_it_has_a_recommended_action_for_every_risk_band(): void
    {
        $bands = IndexActionRecommendation::query()
            ->where('index_id', $this->index()->index_id)
            ->pluck('risk_band');

        $this->assertEqualsCanonicalizing(['green', 'amber', 'red'], $bands->all());
    }

    public function test_it_is_attached_to_the_water_sector(): void
    {
        $this->assertSame(['WATER_SANITATION'], $this->index()->sectors->pluck('code')->all());
    }

    public function test_a_water_sector_follower_sees_it_on_their_dashboard(): void
    {
        $user = User::factory()->create();
        $water = Sector::query()->where('code', 'WATER_SANITATION')->firstOrFail();
        $user->sectorSubscriptions()->create(['sector_id' => $water->sector_id]);

        $codes = IndexCoverage::resolve($user->fresh(), null)['available']->pluck('code');

        $this->assertTrue($codes->contains('DRY_SEASON_WATER_STRESS'));
    }

    public function test_plentiful_water_scores_low_and_scarcity_scores_high(): void
    {
        $region = Region::query()->firstOrFail();
        [$start, $end] = IngestionWindow::lastComplete();

        $write = function (array $values) use ($region, $start, $end) {
            foreach ($values as $code => $value) {
                RegionSignal::query()->updateOrCreate(
                    [
                        'region_id' => $region->region_id,
                        'signal_type_id' => SignalType::query()->where('code', $code)->value('signal_type_id'),
                        'period_start' => $start,
                        'period_end' => $end,
                    ],
                    ['value' => $value, 'source' => 'test', 'ingested_at' => now()],
                );
            }

            return app(RegionScoringService::class)->calculate($this->index(), $region, $start, $end)->score;
        };

        // Everything mid-range -> every signal normalises to 50 -> score 50.
        $this->assertSame(50.0, $write([
            'RAINFALL' => 100, 'STANDING_WATER' => 50, 'SOIL_MOISTURE' => 0.225, 'EVAPOTRANSPIRATION' => 25,
        ]));

        // Wet: lots of rain and surface water, damp soil, low demand -> near zero stress.
        $this->assertSame(0.0, $write([
            'RAINFALL' => 200, 'STANDING_WATER' => 100, 'SOIL_MOISTURE' => 0.40, 'EVAPOTRANSPIRATION' => 0,
        ]));

        // Dry: no rain, no surface water, parched soil, high demand -> maxed stress.
        $this->assertSame(100.0, $write([
            'RAINFALL' => 0, 'STANDING_WATER' => 0, 'SOIL_MOISTURE' => 0.05, 'EVAPOTRANSPIRATION' => 50,
        ]));
    }

    public function test_the_seeder_is_idempotent_and_never_overwrites_a_tuned_weight(): void
    {
        RegionScoringConfig::query()
            ->where('index_id', $this->index()->index_id)
            ->whereNull('region_id')
            ->whereHas('signalType', fn ($q) => $q->where('code', 'RAINFALL'))
            ->update(['weight' => 0.6]);

        $this->seed(AdditionalIndicesSeeder::class);

        $this->assertSame(1, ScoringIndex::query()->where('code', 'DRY_SEASON_WATER_STRESS')->count());
        $this->assertSame(
            4,
            RegionScoringConfig::query()->where('index_id', $this->index()->index_id)->whereNull('region_id')->count(),
        );
        $this->assertEquals(
            0.6,
            RegionScoringConfig::query()
                ->where('index_id', $this->index()->index_id)
                ->whereNull('region_id')
                ->whereHas('signalType', fn ($q) => $q->where('code', 'RAINFALL'))
                ->value('weight'),
        );
    }
}
