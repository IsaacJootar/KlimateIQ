<?php

namespace Tests\Unit;

use App\Support\ScoreDiagnosis;
use PHPUnit\Framework\TestCase;

class ScoreDiagnosisTest extends TestCase
{
    public function test_names_the_signal_with_the_largest_contribution_as_the_driver(): void
    {
        $breakdown = [
            ['signal_type_code' => 'RAINFALL', 'contribution_to_final_score' => 12.5],
            ['signal_type_code' => 'STANDING_WATER', 'contribution_to_final_score' => 55.5],
        ];

        $result = ScoreDiagnosis::forBreakdown($breakdown, 68.0);

        $this->assertSame('STANDING_WATER', $result['dominantSignal']);
        $this->assertSame(55.5, $result['dominantContribution']);
        $this->assertStringContainsString('STANDING_WATER', $result['conclusion']);
        $this->assertStringContainsString('high-risk', $result['conclusion']);
    }

    public function test_uses_the_reader_facing_label_when_one_is_supplied(): void
    {
        $breakdown = [
            ['signal_type_code' => 'STANDING_WATER', 'contribution_to_final_score' => 55.5],
        ];

        $result = ScoreDiagnosis::forBreakdown($breakdown, 68.0, ['STANDING_WATER' => 'Standing Water']);

        $this->assertSame('Standing Water', $result['dominantSignal']);
        $this->assertStringContainsString('Standing Water', $result['conclusion']);
        $this->assertStringNotContainsString('STANDING_WATER', $result['conclusion']);
    }

    public function test_falls_back_to_the_name_in_the_breakdown_row_then_the_code(): void
    {
        $result = ScoreDiagnosis::forBreakdown(
            [['signal_type_code' => 'EVAPOTRANSPIRATION', 'signal_type_name' => 'Evaporation Demand (ET₀)', 'contribution_to_final_score' => 20.0]],
            50.0,
        );

        $this->assertSame('Evaporation Demand (ET₀)', $result['dominantSignal']);
    }

    public function test_no_data_rows_are_ignored_when_picking_the_driver(): void
    {
        $breakdown = [
            ['signal_type_code' => 'TEMPERATURE', 'status' => 'no_data', 'weight' => 0.5],
            ['signal_type_code' => 'VEGETATION', 'contribution_to_final_score' => 40.0],
        ];

        $result = ScoreDiagnosis::forBreakdown($breakdown, 40.0);

        $this->assertSame('VEGETATION', $result['dominantSignal']);
    }

    public function test_returns_nothing_when_there_is_no_score_yet(): void
    {
        $result = ScoreDiagnosis::forBreakdown([], null);

        $this->assertNull($result['dominantSignal']);
        $this->assertNull($result['conclusion']);
    }

    public function test_returns_nothing_when_every_signal_is_missing_data(): void
    {
        $breakdown = [
            ['signal_type_code' => 'RAINFALL', 'status' => 'no_data', 'weight' => 1.0],
        ];

        $result = ScoreDiagnosis::forBreakdown($breakdown, null);

        $this->assertNull($result['dominantSignal']);
        $this->assertNull($result['conclusion']);
    }
}
