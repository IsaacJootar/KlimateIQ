<?php

namespace Tests\Feature;

use App\Events\RegionForecastScoreCalculated;
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
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T4 M2 — the forecast scoring lane. A forward index score is the PEAK of the
 * daily forecast series, mapped against the same calibration bounds the observed engine uses,
 * written to its own table.
 */
class ForecastScoringTest extends TestCase
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
            'code' => 'TEST_FORECAST', 'name' => 'Test Forecast Index', 'is_forecast' => true,
        ]);
        RegionScoringConfig::query()->create([
            'index_id' => $this->index->index_id, 'region_id' => null,
            'signal_type_id' => $this->dischargeTypeId, 'weight' => 1.0, 'enabled' => true,
        ]);
        $this->region = Region::query()->orderBy('region_id')->first();
        // A single-signal discharge index needs a real per-reach bound to score (T4/T5 safety).
        foreach (['MIN' => 0, 'MAX' => 1000] as $suffix => $value) {
            ScoringCalibrationParameter::query()->create([
                'index_id' => $this->index->index_id, 'region_id' => $this->region->region_id,
                'parameter_key' => "RIVER_DISCHARGE_{$suffix}", 'parameter_value' => $value,
                'calibration_status' => 'reference_derived',
            ]);
        }
    }

    private function forecastDay(string $date, float $value, string $issued = '2026-09-01'): void
    {
        RegionForecastSignal::query()->create([
            'region_id' => $this->region->region_id,
            'signal_type_id' => $this->dischargeTypeId,
            'forecast_issued_at' => $issued,
            'target_date' => $date,
            'lead_days' => Carbon::parse($issued)->diffInDays(Carbon::parse($date)),
            'value' => $value,
            'source' => 'test',
            'ingested_at' => now(),
        ]);
    }

    public function test_the_score_is_the_peak_day_and_it_records_when_the_peak_lands(): void
    {
        Event::fake([RegionForecastScoreCalculated::class]);

        $this->forecastDay('2026-09-01', 200);  // 20
        $this->forecastDay('2026-09-04', 750);  // 75  <- peak, +3 days
        $this->forecastDay('2026-09-06', 400);  // 40

        $result = app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'));

        $this->assertSame(75.0, $result->score);
        $this->assertSame('2026-09-04', $result->peakDate->toDateString());
        $this->assertSame(3, $result->leadDaysToPeak);

        $stored = RegionForecastScore::query()->where('index_id', $this->index->index_id)->where('region_id', $this->region->region_id)->first();
        $this->assertEquals(75.0, $stored->score);
        $this->assertSame('2026-09-04', $stored->peak_date->toDateString());
        $this->assertSame(3, $stored->lead_days_to_peak);
        $this->assertSame(3, $stored->horizon_days); // three forecast days on file

        Event::assertDispatched(RegionForecastScoreCalculated::class, fn ($e) => $e->indexId === $this->index->index_id && $e->peakScore === 75.0 && $e->leadDaysToPeak === 3);
    }

    public function test_re_running_replaces_the_single_row_for_that_region_and_index(): void
    {
        $this->forecastDay('2026-09-02', 500);
        app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'));

        RegionForecastSignal::query()->update(['value' => 900]);
        app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'));

        $rows = RegionForecastScore::query()->where('index_id', $this->index->index_id)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals(90.0, $rows->first()->score);
    }

    public function test_no_forecast_coverage_writes_no_row(): void
    {
        $result = app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'));

        $this->assertNull($result->score);
        $this->assertSame(0, RegionForecastScore::query()->count());
    }

    public function test_scoring_a_forecast_index_never_writes_to_region_scores(): void
    {
        $this->forecastDay('2026-09-03', 600);
        app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'));

        $this->assertSame(0, RegionScore::query()->where('index_id', $this->index->index_id)->count());
    }
}
