<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionForecastScore;
use App\Models\RegionForecastSignal;
use App\Models\RegionScoringConfig;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Models\User;
use App\Services\Scoring\RegionForecastScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T4/T5 follow-up — a single-signal river-discharge index (Riverine Flood
 * Forecast) is never scored against a borrowed bound. With no per-reach flood threshold it
 * shows "calibration pending", not a number that pegs a real river at 100.
 */
class CalibrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private ScoringIndex $index;

    private Region $region;

    private int $dischargeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dischargeId = SignalType::query()->where('code', 'RIVER_DISCHARGE')->value('signal_type_id');
        $this->index = ScoringIndex::query()->create(['code' => 'TEST_SAFETY_FF', 'name' => 'Test FF', 'is_forecast' => true]);
        RegionScoringConfig::query()->create([
            'index_id' => $this->index->index_id, 'region_id' => null,
            'signal_type_id' => $this->dischargeId, 'weight' => 1.0, 'enabled' => true,
        ]);
        $this->region = Region::query()->orderBy('region_id')->first();
    }

    private function forecastSeries(float $value = 800): void
    {
        foreach ([1, 2, 3] as $lead) {
            RegionForecastSignal::query()->create([
                'region_id' => $this->region->region_id, 'signal_type_id' => $this->dischargeId, 'member' => 'control',
                'forecast_issued_at' => '2026-09-01', 'target_date' => Carbon::parse('2026-09-01')->addDays($lead)->toDateString(),
                'lead_days' => $lead, 'value' => $value, 'source' => 'test', 'ingested_at' => now(),
            ]);
        }
    }

    private function realBound(): void
    {
        foreach (['MIN' => 0, 'MAX' => 1000] as $suffix => $value) {
            ScoringCalibrationParameter::query()->create([
                'index_id' => $this->index->index_id, 'region_id' => $this->region->region_id,
                'parameter_key' => "RIVER_DISCHARGE_{$suffix}", 'parameter_value' => $value, 'calibration_status' => 'reference_derived',
            ]);
        }
    }

    public function test_no_per_reach_bound_writes_no_score(): void
    {
        $this->forecastSeries(800);

        $result = app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'));

        $this->assertNull($result->score);
        $this->assertSame('calibration_pending', $result->breakdown['status']);
        $this->assertSame(0, RegionForecastScore::query()->where('index_id', $this->index->index_id)->count());
    }

    public function test_a_real_per_reach_bound_lets_it_score(): void
    {
        $this->forecastSeries(800);
        $this->realBound();

        $result = app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'));

        $this->assertEqualsWithDelta(80.0, $result->score, 0.01);
        $this->assertSame(1, RegionForecastScore::query()->where('index_id', $this->index->index_id)->count());
    }

    public function test_a_placeholder_bound_does_not_count(): void
    {
        $this->forecastSeries(800);
        ScoringCalibrationParameter::query()->create([
            'index_id' => $this->index->index_id, 'region_id' => $this->region->region_id,
            'parameter_key' => 'RIVER_DISCHARGE_MAX', 'parameter_value' => 1000, 'calibration_status' => 'placeholder',
        ]);

        $result = app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'));

        $this->assertNull($result->score);
    }

    public function test_a_stale_score_is_retracted_when_calibration_is_lost(): void
    {
        $this->forecastSeries(800);
        $this->realBound();
        app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'));
        $this->assertSame(1, RegionForecastScore::query()->where('index_id', $this->index->index_id)->count());

        // The reach's bound is removed (e.g. a reseed) — the next run must not leave the old number.
        ScoringCalibrationParameter::query()->where('index_id', $this->index->index_id)->delete();
        app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'));

        $this->assertSame(0, RegionForecastScore::query()->where('index_id', $this->index->index_id)->count());
    }

    public function test_the_region_page_shows_calibration_pending_not_a_number(): void
    {
        // The real seeded index, this region on a modelled reach, forecast pulled, no per-reach bound.
        foreach ([1, 2, 3] as $lead) {
            RegionForecastSignal::query()->create([
                'region_id' => $this->region->region_id, 'signal_type_id' => $this->dischargeId, 'member' => 'control',
                'forecast_issued_at' => now()->toDateString(), 'target_date' => now()->addDays($lead)->toDateString(),
                'lead_days' => $lead, 'value' => 6600, 'source' => 'test', 'ingested_at' => now(),
            ]);
        }

        $this->actingAs(User::factory()->create())
            ->get(route('regions.show', ['region' => $this->region, 'index' => 'RIVERINE_FLOOD_FORECAST']))
            ->assertOk()
            ->assertSee("isn't calibrated yet", false)
            ->assertSee('6,600')          // the raw discharge is shown
            ->assertDontSee('High risk')
            ->assertDontSee('score-hero-number');
    }

    public function test_calibrate_command_no_longer_writes_a_system_wide_reference_bound(): void
    {
        // The command's global median-of-reaches fallback write is gone. Seeded [0, 4000]
        // placeholders may exist (status 'placeholder', ignored by the scoring guard) but no
        // reference_derived global bound.
        $this->assertSame(
            0,
            ScoringCalibrationParameter::query()
                ->whereNull('region_id')
                ->where('parameter_key', 'like', 'RIVER_DISCHARGE_%')
                ->where('calibration_status', 'reference_derived')
                ->count(),
        );
    }
}
