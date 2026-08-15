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
