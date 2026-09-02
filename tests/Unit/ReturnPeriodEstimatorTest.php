<?php

namespace Tests\Unit;

use App\Services\Hydrology\ReturnPeriodEstimator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class ReturnPeriodEstimatorTest extends TestCase
{
    private ReturnPeriodEstimator $estimator;

    protected function setUp(): void
    {
        $this->estimator = new ReturnPeriodEstimator;
    }

    public function test_annual_maxima_takes_the_year_high_and_drops_short_years(): void
    {
        $series = [];
        // 2020: a full year, peak 500 on day 100.
        foreach (range(1, 366) as $doy) {
            $date = Carbon::create(2020)->addDays($doy - 1)->toDateString();
            $series[$date] = $doy === 100 ? 500.0 : 100.0;
        }
        // 2021: only 10 days — too short.
        foreach (range(1, 10) as $doy) {
            $series[Carbon::create(2021)->addDays($doy - 1)->toDateString()] = 999.0;
        }

        $maxima = $this->estimator->annualMaxima($series);

        $this->assertSame([2020 => 500.0], $maxima);
    }

    public function test_return_levels_are_monotonic_and_bracket_the_record(): void
    {
        // 20 annual maxima, evenly spaced 100..2000.
        $maxima = [];
        foreach (range(1, 20) as $i) {
            $maxima[2000 + $i] = $i * 100.0;
        }

        $rl = $this->estimator->returnLevels($maxima, [2, 5, 20]);

        $this->assertLessThan($rl['5'], $rl['2']);
        $this->assertLessThan($rl['20'], $rl['5']);
        // 2-year ≈ the median annual flood.
        $this->assertEqualsWithDelta(1050.0, $rl['2'], 100.0);
        // 20-year sits near the top of a 20-value record.
        $this->assertGreaterThan(1800.0, $rl['20']);
    }

    public function test_low_flow_is_near_the_bottom_of_the_record(): void
    {
        $series = [];
        foreach (range(1, 100) as $i) {
            $series['2020-01-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT)."-{$i}"] = $i * 1.0;
        }
        // p10 of 1..100 ≈ 10.
        $this->assertEqualsWithDelta(10.0, $this->estimator->lowFlow($series), 3.0);
    }

    public function test_it_handles_an_empty_record(): void
    {
        $this->assertSame([], $this->estimator->returnLevels([], [2, 5]));
        $this->assertSame(0.0, $this->estimator->lowFlow([]));
    }
}
