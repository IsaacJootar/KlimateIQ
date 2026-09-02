<?php

namespace Tests\Feature;

use App\Models\RegionScoringConfig;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Support\CalibrationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Keeps the platform honest about how trustworthy its scoring is (docs/MODEL.md). Every weight
 * and bound must carry a calibration_status; anything that claims to be more than a guess must
 * say where it came from; and a new index's bounds default to "placeholder" so rigour is never
 * claimed by accident.
 */
class CalibrationHonestyTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_weight_and_bound_carries_a_calibration_status(): void
    {
        $weights = RegionScoringConfig::query()->whereNull('region_id')->get();
        $bounds = ScoringCalibrationParameter::query()->whereNull('region_id')->get();

        $this->assertNotEmpty($weights);
        $this->assertNotEmpty($bounds);

        foreach ($weights->concat($bounds) as $row) {
            $this->assertInstanceOf(CalibrationStatus::class, $row->calibration_status);
        }
    }

    public function test_anything_better_than_a_placeholder_says_where_it_came_from(): void
    {
        $claimsRigour = ScoringCalibrationParameter::query()
            ->whereNull('region_id')
            ->get()
            ->filter(fn ($p) => $p->calibration_status !== CalibrationStatus::Placeholder);

        foreach ($claimsRigour as $p) {
            $this->assertNotEmpty(
                $p->source_reference,
                "{$p->parameter_key} on index {$p->index_id} is marked {$p->calibration_status->value} but has no source_reference",
            );
        }
    }

    public function test_the_pm_bounds_are_the_reference_ones_and_the_climate_defaults_are_placeholders(): void
    {
        $status = fn (string $key) => ScoringCalibrationParameter::query()
            ->whereNull('region_id')->where('parameter_key', $key)->value('calibration_status');

        $this->assertSame(CalibrationStatus::Reference, $status('AIR_QUALITY_PM25_MAX'));
        $this->assertSame(CalibrationStatus::Placeholder, $status('RAINFALL_MAX'));
        $this->assertSame(CalibrationStatus::Placeholder, $status('TEMPERATURE_MAX'));
    }

    public function test_a_new_index_bound_defaults_to_placeholder(): void
    {
        $index = ScoringIndex::query()->create(['code' => 'SYNTHETIC_HONESTY_INDEX', 'name' => 'Synthetic']);

        $param = ScoringCalibrationParameter::query()->create([
            'index_id' => $index->index_id, 'region_id' => null,
            'parameter_key' => 'RAINFALL_MAX', 'parameter_value' => 200,
        ]);

        $this->assertSame(CalibrationStatus::Placeholder, $param->fresh()->calibration_status);
    }

    public function test_no_bound_claims_outcome_validation_yet(): void
    {
        // The day this fails, a real validation study has landed — update the test, not the guard.
        $this->assertSame(
            0,
            ScoringCalibrationParameter::query()->where('calibration_status', CalibrationStatus::OutcomeValidated->value)->count(),
        );
    }
}
