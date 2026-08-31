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
        $this->assertSame('STANDING_WATER', $result['drivers'][0]['code']);
        $this->assertSame(82, $result['drivers'][0]['share']);
    }

    public function test_headline_states_the_band_in_plain_words_and_the_direction(): void
    {
        $breakdown = [['signal_type_code' => 'STANDING_WATER', 'contribution_to_final_score' => 55.5]];

        $high = ScoreDiagnosis::forBreakdown($breakdown, 72.0, [], 'up');
        $this->assertSame('High risk this week and building.', $high['headline']);

        $low = ScoreDiagnosis::forBreakdown([['signal_type_code' => 'RAINFALL', 'contribution_to_final_score' => 10.0]], 20.0, [], 'flat');
        $this->assertSame('Low risk this week and steady.', $low['headline']);
    }

    public function test_conclusion_says_whether_the_other_drivers_agree(): void
    {
        $agree = ScoreDiagnosis::forBreakdown([
            ['signal_type_code' => 'SOIL_MOISTURE', 'contribution_to_final_score' => 25.0],
            ['signal_type_code' => 'RAINFALL', 'contribution_to_final_score' => 20.0],
        ], 50.0, ['SOIL_MOISTURE' => 'Soil moisture', 'RAINFALL' => 'Rainfall']);
        $this->assertStringContainsString('Rainfall is pushing the same way', $agree['conclusion']);

        $lone = ScoreDiagnosis::forBreakdown([
            ['signal_type_code' => 'SOIL_MOISTURE', 'contribution_to_final_score' => 48.0],
            ['signal_type_code' => 'RAINFALL', 'contribution_to_final_score' => 2.0],
        ], 50.0, ['SOIL_MOISTURE' => 'Soil moisture', 'RAINFALL' => 'Rainfall']);
        $this->assertStringContainsString('other signals are near normal', $lone['conclusion']);
    }

    public function test_uses_the_reader_facing_label_when_one_is_supplied(): void
    {
        $result = ScoreDiagnosis::forBreakdown(
            [['signal_type_code' => 'STANDING_WATER', 'contribution_to_final_score' => 55.5]],
            68.0,
            ['STANDING_WATER' => 'Standing Water'],
        );

        $this->assertSame('Standing Water', $result['dominantSignal']);
        $this->assertStringContainsString('Standing Water', $result['conclusion']);
        $this->assertStringNotContainsString('STANDING_WATER', $result['conclusion']);
    }

    public function test_weight_zero_confirmation_signals_are_not_treated_as_drivers(): void
    {
        $result = ScoreDiagnosis::forBreakdown([
            ['signal_type_code' => 'HUMIDITY', 'contribution_to_final_score' => 30.0],
            ['signal_type_code' => 'ACTIVE_FIRE', 'contribution_to_final_score' => 0.0],
        ], 40.0);

        $this->assertCount(1, $result['drivers']);
        $this->assertSame('HUMIDITY', $result['drivers'][0]['code']);
    }

    public function test_no_data_rows_are_ignored_when_picking_the_driver(): void
    {
        $result = ScoreDiagnosis::forBreakdown([
            ['signal_type_code' => 'TEMPERATURE', 'status' => 'no_data', 'weight' => 0.5],
            ['signal_type_code' => 'VEGETATION', 'contribution_to_final_score' => 40.0],
        ], 40.0);

        $this->assertSame('VEGETATION', $result['dominantSignal']);
    }

    public function test_returns_nothing_when_there_is_no_score_yet(): void
    {
        $result = ScoreDiagnosis::forBreakdown([], null);

        $this->assertNull($result['dominantSignal']);
        $this->assertNull($result['conclusion']);
        $this->assertNull($result['headline']);
        $this->assertSame([], $result['drivers']);
    }

    public function test_returns_nothing_when_every_signal_is_missing_data(): void
    {
        $result = ScoreDiagnosis::forBreakdown(
            [['signal_type_code' => 'RAINFALL', 'status' => 'no_data', 'weight' => 1.0]],
            null,
        );

        $this->assertNull($result['dominantSignal']);
        $this->assertNull($result['conclusion']);
    }
}
