<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionForecastScore;
use App\Models\RegionForecastSignal;
use App\Models\RegionScore;
use App\Models\RegionScoringConfig;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Services\Scoring\RegionForecastScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T5 M2 — the ensemble members are scored through the same formula as the control
 * run and reduced to p10 / p50 / p90 + an exceedance probability, folded into the same
 * region_forecast_scores row. The control `score` is untouched.
 */
class ProbabilisticScoringTest extends TestCase
{
    use RefreshDatabase;

    private ScoringIndex $index;

    private Region $region;

    private int $dischargeTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dischargeTypeId = SignalType::query()->where('code', 'RIVER_DISCHARGE')->value('signal_type_id');
        $this->index = ScoringIndex::query()->create([
            'code' => 'TEST_PROB', 'name' => 'Test Probabilistic Index', 'is_forecast' => true,
        ]);
        RegionScoringConfig::query()->create([
            'index_id' => $this->index->index_id, 'region_id' => null,
            'signal_type_id' => $this->dischargeTypeId, 'weight' => 1.0, 'enabled' => true,
        ]);
        foreach (['MIN' => 0, 'MAX' => 1000] as $suffix => $value) {
            ScoringCalibrationParameter::query()->create([
                'index_id' => $this->index->index_id, 'region_id' => null,
                'parameter_key' => "RIVER_DISCHARGE_{$suffix}", 'parameter_value' => $value,
            ]);
        }
        $this->region = Region::query()->orderBy('region_id')->first();
    }

    private function signal(string $member, string $date, float $value, string $issued = '2026-09-01'): void
    {
        RegionForecastSignal::query()->create([
            'region_id' => $this->region->region_id,
            'signal_type_id' => $this->dischargeTypeId,
            'member' => $member,
            'forecast_issued_at' => $issued,
            'target_date' => $date,
            'lead_days' => (int) Carbon::parse($issued)->diffInDays(Carbon::parse($date)),
            'value' => $value,
            'source' => 'test',
            'ingested_at' => now(),
        ]);
    }

    /** control peak 50; members split so that exactly 4 of 10 peak at/above the 670 (→ score 67) line. */
    private function seedSpread(): void
    {
        $this->signal('control', '2026-09-02', 300);
        $this->signal('control', '2026-09-03', 500); // control peak → 50

        for ($m = 1; $m <= 10; $m++) {
            $id = 'glofas-'.str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            $peak = $m <= 4 ? 720 : 300; // 4 members peak at 72, 6 at 30
            $this->signal($id, '2026-09-02', 200);
            $this->signal($id, '2026-09-03', $peak);
        }
    }

    public function test_it_writes_percentiles_and_an_exceedance_probability_without_moving_the_control_score(): void
    {
        $this->seedSpread();

        $result = app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'));

        $this->assertSame(50.0, $result->score, 'control score is the control peak, unchanged');

        $row = RegionForecastScore::query()->where('index_id', $this->index->index_id)->first();
        $this->assertEquals(50.0, $row->score);
        $this->assertSame(10, $row->member_count);
        $this->assertEqualsWithDelta(0.4, (float) $row->exceedance_probability, 0.001); // 4/10 ≥ 67
        $this->assertEquals(67, (float) $row->exceedance_reference);
        $this->assertLessThanOrEqual((float) $row->p50, (float) $row->p10); // p10 ≤ p50
        $this->assertLessThanOrEqual((float) $row->p90, (float) $row->p50); // p50 ≤ p90
        $this->assertGreaterThanOrEqual(67, (float) $row->p90); // top 4 members
        $this->assertLessThan(67, (float) $row->p10);

        $this->assertCount(10, $row->breakdown['members']);
        $this->assertNotEmpty($row->breakdown['member_daily']);
    }

    public function test_fewer_than_five_members_leaves_the_distribution_null(): void
    {
        $this->signal('control', '2026-09-03', 500);
        foreach (['glofas-01', 'glofas-02', 'glofas-03'] as $m) {
            $this->signal($m, '2026-09-03', 700);
        }

        app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'));

        $row = RegionForecastScore::query()->where('index_id', $this->index->index_id)->first();
        $this->assertEquals(50.0, $row->score);
        $this->assertNull($row->p50);
        $this->assertNull($row->exceedance_probability);
        $this->assertNull($row->member_count);
    }

    public function test_no_ensemble_flag_skips_the_pass(): void
    {
        $this->seedSpread();

        app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'), withEnsemble: false);

        $row = RegionForecastScore::query()->where('index_id', $this->index->index_id)->first();
        $this->assertEquals(50.0, $row->score);
        $this->assertNull($row->p50);
    }

    public function test_the_ensemble_pass_never_writes_to_region_scores(): void
    {
        $this->seedSpread();
        $observedBefore = RegionScore::query()->count();

        app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'));

        $this->assertSame($observedBefore, RegionScore::query()->count());
    }

    public function test_a_two_signal_index_pairs_members_and_falls_back_for_a_missing_one(): void
    {
        $rainId = SignalType::query()->where('code', 'RAINFALL')->value('signal_type_id');
        $tempId = SignalType::query()->where('code', 'TEMPERATURE')->value('signal_type_id');

        $twoSig = ScoringIndex::query()->create(['code' => 'TEST_PROB_2', 'name' => 'Two-signal', 'is_forecast' => true]);
        foreach ([$rainId, $tempId] as $sid) {
            RegionScoringConfig::query()->create([
                'index_id' => $twoSig->index_id, 'region_id' => null, 'signal_type_id' => $sid, 'weight' => 1.0, 'enabled' => true,
            ]);
        }
        foreach (['RAINFALL', 'TEMPERATURE'] as $code) {
            foreach (['MIN' => 0, 'MAX' => 100] as $suffix => $value) {
                ScoringCalibrationParameter::query()->create([
                    'index_id' => $twoSig->index_id, 'region_id' => null,
                    'parameter_key' => "{$code}_{$suffix}", 'parameter_value' => $value,
                ]);
            }
        }

        // latest observed temperature — the flat fallback for members that lack a temp series.
        $this->region->signals()->create([
            'signal_type_id' => $tempId, 'period_start' => '2026-08-24', 'period_end' => '2026-08-30',
            'value' => 40, 'source' => 'test', 'ingested_at' => now(),
        ]);

        $put = function (int $sid, string $member, float $value) {
            RegionForecastSignal::query()->create([
                'region_id' => $this->region->region_id, 'signal_type_id' => $sid, 'member' => $member,
                'forecast_issued_at' => '2026-09-01', 'target_date' => '2026-09-03', 'lead_days' => 2,
                'value' => $value, 'source' => 'test', 'ingested_at' => now(),
            ]);
        };

        // A control series so the deterministic score writes a row for the ensemble pass to enrich.
        $put($rainId, 'control', 50);

        // 6 rainfall members; temperature only has 4 of them — the other 2 fall back to observed 40.
        for ($m = 1; $m <= 6; $m++) {
            $id = 'gfs-'.str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            $put($rainId, $id, 60);
            if ($m <= 4) {
                $put($tempId, $id, 80);
            }
        }

        app(RegionForecastScoringService::class)->calculate($twoSig, $this->region, Carbon::parse('2026-09-01'));

        $row = RegionForecastScore::query()->where('index_id', $twoSig->index_id)->first();
        $this->assertSame(6, $row->member_count);
        // 4 members: (rain 60 + temp 80)/2 = 70; 2 members: (60 + 40)/2 = 50. p90 ≈ 70, p10 ≈ 50.
        $this->assertGreaterThanOrEqual(65, (float) $row->p90);
        $this->assertLessThanOrEqual(55, (float) $row->p10);
    }
}
