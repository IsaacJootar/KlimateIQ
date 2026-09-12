<?php

namespace Tests\Feature;

use App\Models\IndexActionRecommendation;
use App\Models\Region;
use App\Models\RegionScore;
use App\Models\RegionScoringConfig;
use App\Models\RegionSignal;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Services\Scoring\RegionScoringService;
use App\Support\IngestionWindow;
use Database\Seeders\AdditionalIndicesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T2 — Meningitis Risk. A config-only index like Waterborne Disease, but
 * deliberately region_id-scoped to the Sahel meningitis-belt states rather than a system
 * default, so it doesn't render nationwide.
 */
class MeningitisRiskIndexTest extends TestCase
{
    use RefreshDatabase;

    private function index(): ScoringIndex
    {
        return ScoringIndex::query()->where('code', 'MENINGITIS_RISK')->firstOrFail();
    }

    private function beltRegion(): Region
    {
        return Region::query()->where('state', 'Sokoto')->firstOrFail();
    }

    private function nonBeltRegion(): Region
    {
        return Region::query()->where('state', 'Lagos')->firstOrFail();
    }

    public function test_the_index_is_seeded(): void
    {
        $this->assertSame('Meningitis Risk Index', $this->index()->name);
    }

    public function test_it_has_no_system_default_weights(): void
    {
        // The whole point: no region_id=null row, or every LGA in the country would score it.
        $this->assertSame(
            0,
            RegionScoringConfig::query()->where('index_id', $this->index()->index_id)->whereNull('region_id')->count(),
        );
    }

    public function test_a_belt_region_is_weighted_on_humidity_dust_and_temperature(): void
    {
        $configs = RegionScoringConfig::query()
            ->where('index_id', $this->index()->index_id)
            ->where('region_id', $this->beltRegion()->region_id)
            ->with('signalType')
            ->get();

        $this->assertEqualsCanonicalizing(
            ['HUMIDITY', 'DUST', 'TEMPERATURE'],
            $configs->pluck('signalType.code')->all(),
        );
        $this->assertEquals(0.4, $configs->firstWhere('signalType.code', 'HUMIDITY')->weight);
        $this->assertEquals(0.4, $configs->firstWhere('signalType.code', 'DUST')->weight);
        $this->assertEquals(0.2, $configs->firstWhere('signalType.code', 'TEMPERATURE')->weight);
        $this->assertFalse((bool) $configs->firstWhere('signalType.code', 'HUMIDITY')->higher_is_worse);
    }

    public function test_a_non_belt_region_has_no_weights(): void
    {
        $this->assertSame(
            0,
            RegionScoringConfig::query()
                ->where('index_id', $this->index()->index_id)
                ->where('region_id', $this->nonBeltRegion()->region_id)
                ->count(),
        );
    }

    public function test_it_has_a_recommended_action_for_every_risk_band(): void
    {
        $bands = IndexActionRecommendation::query()
            ->where('index_id', $this->index()->index_id)
            ->pluck('risk_band');

        $this->assertEqualsCanonicalizing(['green', 'amber', 'red'], $bands->all());
    }

    public function test_it_is_attached_to_the_public_health_sector(): void
    {
        $this->assertEqualsCanonicalizing(['PUBLIC_HEALTH'], $this->index()->sectors->pluck('code')->all());
    }

    public function test_the_scoring_engine_scores_a_belt_region(): void
    {
        $region = $this->beltRegion();
        [$start, $end] = IngestionWindow::lastComplete();

        foreach (['HUMIDITY' => 20, 'DUST' => 250, 'TEMPERATURE' => 30] as $code => $value) {
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

        $this->assertNotNull($result->score);
    }

    public function test_the_scoring_engine_produces_no_score_for_a_non_belt_region(): void
    {
        $region = $this->nonBeltRegion();
        [$start, $end] = IngestionWindow::lastComplete();

        foreach (['HUMIDITY' => 20, 'DUST' => 250, 'TEMPERATURE' => 30] as $code => $value) {
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

        $this->assertNull($result->score);
    }

    public function test_scores_calculate_picks_up_the_new_index_for_a_belt_region(): void
    {
        $region = $this->beltRegion();
        [$start, $end] = IngestionWindow::lastComplete();

        foreach (['HUMIDITY' => 15, 'DUST' => 300, 'TEMPERATURE' => 35] as $code => $value) {
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
        $region = $this->beltRegion();

        RegionScoringConfig::query()
            ->where('index_id', $this->index()->index_id)
            ->where('region_id', $region->region_id)
            ->whereHas('signalType', fn ($q) => $q->where('code', 'DUST'))
            ->update(['weight' => 0.7]);

        $this->seed(AdditionalIndicesSeeder::class);

        $this->assertSame(1, ScoringIndex::query()->where('code', 'MENINGITIS_RISK')->count());
        $this->assertEquals(
            0.7,
            RegionScoringConfig::query()
                ->where('index_id', $this->index()->index_id)
                ->where('region_id', $region->region_id)
                ->whereHas('signalType', fn ($q) => $q->where('code', 'DUST'))
                ->value('weight'),
        );
    }
}
