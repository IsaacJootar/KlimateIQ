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
 * BUILD_PLAN.md T2 — Waterborne Disease Risk. A config-only index: reuses the STANDING_WATER
 * and RAINFALL signals already ingested for Malaria / Flood Risk, no new ingestion.
 */
class WaterborneDiseaseIndexTest extends TestCase
{
    use RefreshDatabase;

    private function index(): ScoringIndex
    {
        return ScoringIndex::query()->where('code', 'WATERBORNE_DISEASE_RISK')->firstOrFail();
    }

    public function test_the_index_is_seeded(): void
    {
        $this->assertSame('Waterborne Disease Risk Index', $this->index()->name);
    }

    public function test_it_is_weighted_on_standing_water_and_rainfall_only(): void
    {
        $configs = RegionScoringConfig::query()
            ->where('index_id', $this->index()->index_id)
            ->whereNull('region_id')
            ->with('signalType')
            ->get();

        $this->assertEqualsCanonicalizing(
            ['STANDING_WATER', 'RAINFALL'],
            $configs->pluck('signalType.code')->all(),
        );
        $this->assertEquals(0.5, $configs->firstWhere('signalType.code', 'STANDING_WATER')->weight);
        $this->assertEquals(0.5, $configs->firstWhere('signalType.code', 'RAINFALL')->weight);
        $this->assertTrue($configs->every(fn ($c) => $c->enabled));
    }

    public function test_it_has_a_recommended_action_for_every_risk_band(): void
    {
        $bands = IndexActionRecommendation::query()
            ->where('index_id', $this->index()->index_id)
            ->pluck('risk_band');

        $this->assertEqualsCanonicalizing(['green', 'amber', 'red'], $bands->all());
    }

    public function test_it_is_attached_to_the_public_health_and_water_sectors(): void
    {
        $this->assertEqualsCanonicalizing(
            ['PUBLIC_HEALTH', 'WATER_SANITATION'],
            $this->index()->sectors->pluck('code')->all(),
        );
    }

    public function test_a_water_sector_follower_sees_it_on_their_dashboard(): void
    {
        $user = User::factory()->create();
        $water = Sector::query()->where('code', 'WATER_SANITATION')->firstOrFail();
        $user->sectorSubscriptions()->create(['sector_id' => $water->sector_id]);

        $codes = IndexCoverage::resolve($user->fresh(), null)['available']->pluck('code');

        $this->assertTrue($codes->contains('WATERBORNE_DISEASE_RISK'));
    }

    public function test_the_scoring_engine_produces_a_score_from_the_two_signals(): void
    {
        $region = Region::query()->firstOrFail();
        [$start, $end] = IngestionWindow::lastComplete();

        foreach (['RAINFALL' => 100, 'STANDING_WATER' => 40] as $code => $value) {
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

        // RAINFALL 100/200 -> 50, STANDING_WATER 40/100 -> 40, equal weight -> 45.
        $this->assertSame(45.0, $result->score);
    }

    public function test_scores_calculate_picks_up_the_new_index_automatically(): void
    {
        $region = Region::query()->firstOrFail();
        $region->subscribers()->create(['user_id' => User::factory()->create()->id]);
        [$start, $end] = IngestionWindow::lastComplete();

        foreach (['RAINFALL' => 80, 'STANDING_WATER' => 60] as $code => $value) {
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
        // Admin tunes the standing-water weight up.
        RegionScoringConfig::query()
            ->where('index_id', $this->index()->index_id)
            ->whereNull('region_id')
            ->whereHas('signalType', fn ($q) => $q->where('code', 'STANDING_WATER'))
            ->update(['weight' => 0.8]);

        $this->seed(AdditionalIndicesSeeder::class);

        $this->assertSame(1, ScoringIndex::query()->where('code', 'WATERBORNE_DISEASE_RISK')->count());
        $this->assertSame(
            2,
            RegionScoringConfig::query()->where('index_id', $this->index()->index_id)->whereNull('region_id')->count(),
        );
        $this->assertEquals(
            0.8,
            RegionScoringConfig::query()
                ->where('index_id', $this->index()->index_id)
                ->whereNull('region_id')
                ->whereHas('signalType', fn ($q) => $q->where('code', 'STANDING_WATER'))
                ->value('weight'),
        );
    }
}
