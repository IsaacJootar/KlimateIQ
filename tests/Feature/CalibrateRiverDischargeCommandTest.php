<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Support\CalibrationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T4 — per-LGA river-discharge bounds from GloFAS reanalysis return periods, so
 * the Riverine Flood Forecast index discriminates between a small stream and the Niger and its
 * score means "near the N-year flood level", not "high-ish compared to the last 12 months".
 */
class CalibrateRiverDischargeCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $indexId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->indexId = ScoringIndex::query()->where('code', 'RIVERINE_FLOOD_FORECAST')->value('index_id');
    }

    private function region(): Region
    {
        $region = Region::query()->orderBy('region_id')->first();
        $region->update(['latitude' => 7.8, 'longitude' => 6.74]);
        $region->signals()->create([
            'signal_type_id' => SignalType::query()->value('signal_type_id'),
            'period_start' => '2026-08-10', 'period_end' => '2026-08-16', 'value' => 1, 'source' => 'test', 'ingested_at' => now(),
        ]);

        return $region->fresh();
    }

    /** Flipped mid-test to simulate a later reanalysis with different numbers. */
    private bool $hugeFlood = false;

    /**
     * One fake for the whole test: ~30 years of daily discharge, a low baseline with one clear
     * annual flood peak that grows over the record — enough spread for a real annual-maximum
     * series. `$this->hugeFlood` swaps in a bigger, flat flood so a re-run is visibly different.
     */
    private function fakeReanalysis(): void
    {
        Http::fake(['flood-api.open-meteo.com/*' => function ($request) {
            $start = Carbon::parse($request['start_date']);
            $end = Carbon::parse($request['end_date']);
            $time = [];
            $q = [];
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $time[] = $d->toDateString();
                $isPeakDay = $d->month === 9 && $d->day === 15;
                if ($this->hugeFlood) {
                    $q[] = $isPeakDay ? 99999.0 : 50.0;
                } else {
                    $yearIdx = $d->year - $start->year;
                    $q[] = 100.0 + ($d->dayOfYear % 7) + ($isPeakDay ? 800 + $yearIdx * 40 : 0);
                }
            }

            return Http::response(['daily' => ['time' => $time, 'river_discharge' => $q]], 200);
        }]);
    }

    private function bound(int $regionId, string $suffix): ?ScoringCalibrationParameter
    {
        return ScoringCalibrationParameter::query()
            ->where('index_id', $this->indexId)->where('region_id', $regionId)
            ->where('parameter_key', "RIVER_DISCHARGE_{$suffix}")
            ->first();
    }

    public function test_it_derives_return_period_bounds_from_the_reanalysis(): void
    {
        $region = $this->region();
        $this->fakeReanalysis();

        $this->artisan('calibrate:river-discharge', ['--start-year' => 1994])->assertSuccessful();

        $max = $this->bound($region->region_id, 'MAX');
        $min = $this->bound($region->region_id, 'MIN');

        $this->assertNotNull($max);
        $this->assertSame(CalibrationStatus::ReferenceDerived, $max->calibration_status);
        $this->assertSame('weibull-annual-maxima', $max->parameter_metadata['method']);
        $this->assertArrayHasKey('20', $max->parameter_metadata['return_levels']);
        // MAX is the 20-year level — well above the low-flow MIN.
        $this->assertGreaterThan((float) $min->parameter_value, (float) $max->parameter_value);
        $this->assertGreaterThan(800, (float) $max->parameter_value);
        $this->assertStringContainsString('return level', $max->source_reference);
    }

    public function test_a_reach_with_too_short_a_record_is_skipped(): void
    {
        $region = $this->region();
        Http::fake(['flood-api.open-meteo.com/*' => Http::response([
            'daily' => ['time' => ['2026-01-01', '2026-01-02'], 'river_discharge' => [100, 110]],
        ], 200)]);

        $this->artisan('calibrate:river-discharge', ['--min-years' => 15])->assertSuccessful();

        $this->assertNull($this->bound($region->region_id, 'MAX'));
    }

    public function test_it_never_overwrites_a_hand_tuned_bound(): void
    {
        $region = $this->region();
        $this->fakeReanalysis();

        ScoringCalibrationParameter::query()->create([
            'index_id' => $this->indexId, 'region_id' => $region->region_id,
            'parameter_key' => 'RIVER_DISCHARGE_MAX', 'parameter_value' => 12345,
            'source_reference' => 'Hand-set by the Kogi State hydrologist.',
            'calibration_status' => CalibrationStatus::AdminTuned->value,
        ]);

        $this->artisan('calibrate:river-discharge', ['--start-year' => 1994])->assertSuccessful();

        $this->assertEquals(12345, (float) $this->bound($region->region_id, 'MAX')->parameter_value);
        // MIN had no hand value, so it still gets derived.
        $this->assertSame(CalibrationStatus::ReferenceDerived, $this->bound($region->region_id, 'MIN')->calibration_status);
    }

    public function test_a_second_run_skips_an_already_calibrated_reach_unless_refreshed(): void
    {
        $region = $this->region();
        $this->fakeReanalysis();

        $this->artisan('calibrate:river-discharge', ['--start-year' => 1994])->assertSuccessful();
        $firstMax = (float) $this->bound($region->region_id, 'MAX')->parameter_value;

        // Later reanalysis has much bigger floods; a plain re-run leaves the calibrated reach alone.
        $this->hugeFlood = true;
        $this->artisan('calibrate:river-discharge', ['--start-year' => 1994])->assertSuccessful();
        $this->assertEquals($firstMax, (float) $this->bound($region->region_id, 'MAX')->parameter_value);

        // --refresh recomputes it.
        $this->artisan('calibrate:river-discharge', ['--start-year' => 1994, '--refresh' => true])->assertSuccessful();
        $this->assertNotEquals($firstMax, (float) $this->bound($region->region_id, 'MAX')->parameter_value);
    }
}
