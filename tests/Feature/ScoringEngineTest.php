<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Services\Scoring\RegionScoringService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScoringEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function periodStart(): Carbon
    {
        return Carbon::parse('2026-07-26');
    }

    private function periodEnd(): Carbon
    {
        return Carbon::parse('2026-08-01');
    }

    public function test_formula_strategy_scores_a_single_available_signal(): void
    {
        $region = Region::query()->where('lga_code', 'NG-LA-IKJ')->firstOrFail();
        $index = ScoringIndex::query()->where('code', 'MALARIA_RISK')->firstOrFail();
        $rainfall = SignalType::query()->where('code', 'RAINFALL')->firstOrFail();

        // Calibration bounds for MALARIA_RISK/RAINFALL are 0-200mm, so 100mm normalizes to 50.
        RegionSignal::query()->create([
            'region_id' => $region->region_id,
            'signal_type_id' => $rainfall->signal_type_id,
            'period_start' => $this->periodStart(),
            'period_end' => $this->periodEnd(),
            'value' => 100,
            'source' => 'test',
            'ingested_at' => now(),
        ]);

        $result = app(RegionScoringService::class)->calculate($index, $region, $this->periodStart(), $this->periodEnd());

        // Only RAINFALL has data; STANDING_WATER is skipped and the weight renormalizes to
        // 100% RAINFALL, so the score equals RAINFALL's own normalized value.
        $this->assertSame(50.0, $result->score);
        $this->assertCount(2, $result->breakdown);
        $this->assertSame('no_data', collect($result->breakdown)->firstWhere('signal_type_code', 'STANDING_WATER')['status']);

        // RAINFALL is the only signal with data, so its true share of the final score is the
        // whole score itself.
        $rainfallRow = collect($result->breakdown)->firstWhere('signal_type_code', 'RAINFALL');
        $this->assertSame(50.0, $rainfallRow['contribution_to_final_score']);
    }

    public function test_contribution_to_final_score_sums_to_the_score_across_multiple_signals(): void
    {
        $region = Region::query()->where('lga_code', 'NG-LA-IKJ')->firstOrFail();
        $index = ScoringIndex::query()->where('code', 'MALARIA_RISK')->firstOrFail();
        $rainfall = SignalType::query()->where('code', 'RAINFALL')->firstOrFail();
        $standingWater = SignalType::query()->where('code', 'STANDING_WATER')->firstOrFail();

        // MALARIA_RISK weights RAINFALL and STANDING_WATER 0.5/0.5. Bounds: rainfall 0-200mm,
        // standing water 0-100%. 100mm -> 50, 80% -> 80.
        foreach ([$rainfall->signal_type_id => 100, $standingWater->signal_type_id => 80] as $signalTypeId => $value) {
            RegionSignal::query()->create([
                'region_id' => $region->region_id,
                'signal_type_id' => $signalTypeId,
                'period_start' => $this->periodStart(),
                'period_end' => $this->periodEnd(),
                'value' => $value,
                'source' => 'test',
                'ingested_at' => now(),
            ]);
        }

        $result = app(RegionScoringService::class)->calculate($index, $region, $this->periodStart(), $this->periodEnd());

        $summedContributions = collect($result->breakdown)->sum('contribution_to_final_score');

        $this->assertEqualsWithDelta($result->score, $summedContributions, 0.01);
    }

    public function test_formula_strategy_returns_null_score_when_no_signals_available(): void
    {
        $region = Region::query()->where('lga_code', 'NG-LA-IKJ')->firstOrFail();
        $index = ScoringIndex::query()->where('code', 'MALARIA_RISK')->firstOrFail();

        $result = app(RegionScoringService::class)->calculate($index, $region, $this->periodStart(), $this->periodEnd());

        $this->assertNull($result->score);
    }

    public function test_elevation_signal_normalizes_inverted_for_flood_risk(): void
    {
        $region = Region::query()->where('lga_code', 'NG-BY-YNG')->firstOrFail();
        $index = ScoringIndex::query()->where('code', 'FLOOD_RISK')->firstOrFail();
        $elevation = SignalType::query()->where('code', 'ELEVATION')->firstOrFail();

        // Bounds are 0-500m. A LOW elevation (100m) should read as HIGH flood risk (80),
        // not low — elevation is the one inverted ("higher_is_worse: false") signal.
        RegionSignal::query()->create([
            'region_id' => $region->region_id,
            'signal_type_id' => $elevation->signal_type_id,
            'period_start' => $this->periodStart(),
            'period_end' => $this->periodEnd(),
            'value' => 100,
            'source' => 'test',
            'ingested_at' => now(),
        ]);

        $result = app(RegionScoringService::class)->calculate($index, $region, $this->periodStart(), $this->periodEnd());

        $elevationRow = collect($result->breakdown)->firstWhere('signal_type_code', 'ELEVATION');
        $this->assertSame(80.0, $elevationRow['normalized_score']);
    }

    public function test_calculate_persists_a_region_score_row(): void
    {
        $region = Region::query()->where('lga_code', 'NG-LA-IKJ')->firstOrFail();
        $index = ScoringIndex::query()->where('code', 'MALARIA_RISK')->firstOrFail();

        app(RegionScoringService::class)->calculate($index, $region, $this->periodStart(), $this->periodEnd());

        $this->assertDatabaseHas('region_scores', [
            'index_id' => $index->index_id,
            'region_id' => $region->region_id,
            'period_start' => $this->periodStart()->toDateString(),
        ]);
    }
}
