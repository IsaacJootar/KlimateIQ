<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T4 — per-LGA river-discharge bounds from observed history, so the Riverine
 * Flood Forecast index discriminates between a small stream and the Niger.
 */
class CalibrateRiverDischargeCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $indexId;

    private int $dischargeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->indexId = ScoringIndex::query()->where('code', 'RIVERINE_FLOOD_FORECAST')->value('index_id');
        $this->dischargeId = SignalType::query()->where('code', 'RIVER_DISCHARGE')->value('signal_type_id');
    }

    private function seedHistory(Region $region, array $values): void
    {
        foreach ($values as $i => $value) {
            $start = Carbon::parse('2026-06-01')->addWeeks($i);
            $region->signals()->create([
                'signal_type_id' => $this->dischargeId,
                'period_start' => $start->toDateString(), 'period_end' => $start->copy()->addDays(6)->toDateString(),
                'value' => $value, 'source' => 'test', 'ingested_at' => now(),
            ]);
        }
    }

    private function bound(int $regionId, string $suffix): ?float
    {
        $v = ScoringCalibrationParameter::query()
            ->where('index_id', $this->indexId)->where('region_id', $regionId)
            ->where('parameter_key', "RIVER_DISCHARGE_{$suffix}")->value('parameter_value');

        return $v === null ? null : (float) $v;
    }

    public function test_it_derives_per_region_bounds_from_the_observed_record(): void
    {
        $region = Region::query()->orderBy('region_id')->first();
        $this->seedHistory($region, [1000, 1500, 2000, 2500, 3000]); // max 3000, min 1000

        $this->artisan('calibrate:river-discharge')->assertSuccessful();

        $this->assertEqualsWithDelta(4200.0, $this->bound($region->region_id, 'MAX'), 0.01); // 3000 * 1.4
        $this->assertEqualsWithDelta(800.0, $this->bound($region->region_id, 'MIN'), 0.01);  // 1000 * 0.8
    }

    public function test_a_region_with_too_little_history_is_skipped(): void
    {
        $region = Region::query()->orderBy('region_id')->first();
        $this->seedHistory($region, [1000, 2000]); // only 2 readings

        $this->artisan('calibrate:river-discharge')->assertSuccessful();

        $this->assertNull($this->bound($region->region_id, 'MAX'));
    }

    public function test_it_never_overwrites_a_hand_tuned_bound(): void
    {
        $region = Region::query()->orderBy('region_id')->first();
        $this->seedHistory($region, [1000, 1500, 2000, 2500, 3000]);

        ScoringCalibrationParameter::query()->create([
            'index_id' => $this->indexId, 'region_id' => $region->region_id,
            'parameter_key' => 'RIVER_DISCHARGE_MAX', 'parameter_value' => 9999,
            'source_reference' => 'Hand-set by the state hydrologist.',
        ]);

        $this->artisan('calibrate:river-discharge')->assertSuccessful();

        $this->assertEqualsWithDelta(9999.0, $this->bound($region->region_id, 'MAX'), 0.01);
        // MIN had no hand value, so it still gets derived.
        $this->assertEqualsWithDelta(800.0, $this->bound($region->region_id, 'MIN'), 0.01);
    }
}
