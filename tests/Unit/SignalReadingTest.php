<?php

namespace Tests\Unit;

use App\Support\SignalReading;
use PHPUnit\Framework\TestCase;

class SignalReadingTest extends TestCase
{
    public function test_phrases_a_value_with_a_climatology_grounded_adjective(): void
    {
        $this->assertSame('8 mm of rain — very little', SignalReading::describe('RAINFALL', 8)['sentence']);
        $this->assertSame('95 mm of rain — heavy', SignalReading::describe('RAINFALL', 95)['sentence']);

        $hot = SignalReading::describe('TEMPERATURE', 39);
        $this->assertSame('very hot', $hot['adjective']);
        $this->assertStringContainsString('39', $hot['sentence']);
    }

    public function test_unknown_signals_fall_back_to_stating_the_value(): void
    {
        $result = SignalReading::describe('POPULATION_EXPOSURE', 313196);

        $this->assertSame('', $result['adjective']);
        $this->assertStringContainsString('313196', $result['sentence']);
    }

    public function test_versus_recent_only_speaks_on_a_clear_change(): void
    {
        // 0.14 vs a recent mean of 0.22 — a fall of ~36%, worth saying.
        $this->assertStringContainsString('down from', SignalReading::versusRecent('SOIL_MOISTURE', 0.14, 0.22));
        // 0.21 vs 0.22 — within noise, say nothing.
        $this->assertSame('', SignalReading::versusRecent('SOIL_MOISTURE', 0.21, 0.22));
        // No history.
        $this->assertSame('', SignalReading::versusRecent('SOIL_MOISTURE', 0.14, null));
    }
}
