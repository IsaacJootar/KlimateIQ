<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use App\Models\User;
use App\Support\IngestionCadence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SignalCadenceLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cadence_helper_classifies_correctly(): void
    {
        $this->assertTrue(IngestionCadence::isWeekly('VEGETATION'));
        $this->assertFalse(IngestionCadence::isWeekly('TEMPERATURE'));
        $this->assertFalse(IngestionCadence::isWeekly('AIR_QUALITY_PM25'));
        $this->assertFalse(IngestionCadence::isWeekly('AIR_QUALITY_PM10'));
        $this->assertFalse(IngestionCadence::isWeekly('RAINFALL'));
        $this->assertFalse(IngestionCadence::isWeekly('STANDING_WATER'));
        $this->assertFalse(IngestionCadence::isWeekly('ELEVATION'));
        $this->assertFalse(IngestionCadence::isWeekly('POPULATION_EXPOSURE'));
    }

    /**
     * Heat Stress mixes a daily-tier signal (Temperature) and the one remaining weekly-tier
     * signal (Vegetation) — a real scenario for checking both labels appear correctly side by
     * side, not just in isolation.
     */
    public function test_a_missing_weekly_signal_shows_pending_while_a_missing_daily_signal_shows_no_data(): void
    {
        $user = User::factory()->create();
        $region = Region::query()->first();
        $index = ScoringIndex::where('code', 'HEAT_STRESS_RISK')->firstOrFail();

        RegionScore::create([
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'period_start' => Carbon::parse('2026-08-03'),
            'period_end' => Carbon::parse('2026-08-09'),
            'score' => null,
            'scoring_strategy' => 'formula',
            'breakdown' => [
                ['signal_type_code' => 'TEMPERATURE', 'status' => 'no_data', 'weight' => 0.7],
                ['signal_type_code' => 'VEGETATION', 'status' => 'no_data', 'weight' => 0.3],
            ],
            'calculated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'HEAT_STRESS_RISK']));

        $response->assertSee("pending this week's update", false);
        $response->assertSee('<span class="text-gray-400 italic">no data</span>', false);
    }

    public function test_a_missing_daily_cadence_signal_still_shows_no_data(): void
    {
        $user = User::factory()->create();
        $region = Region::query()->first();
        $index = ScoringIndex::where('code', 'MALARIA_RISK')->firstOrFail();

        RegionScore::create([
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'period_start' => Carbon::parse('2026-08-03'),
            'period_end' => Carbon::parse('2026-08-09'),
            'score' => null,
            'scoring_strategy' => 'formula',
            'breakdown' => [
                ['signal_type_code' => 'RAINFALL', 'status' => 'no_data', 'weight' => 0.5],
                ['signal_type_code' => 'STANDING_WATER', 'status' => 'no_data', 'weight' => 0.5],
            ],
            'calculated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'MALARIA_RISK']));

        $response->assertSee('<span class="text-gray-400 italic">no data</span>', false);
        $response->assertDontSee("pending this week's update", false);
    }
}
