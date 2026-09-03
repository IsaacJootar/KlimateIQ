<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionForecastScore;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use App\Models\User;
use App\Support\LatestScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T4 M3 — the region page for a forecast index reads its own lane and tells a
 * forecast story (peak + lead time), while an observed index on the same region is untouched.
 */
class RegionForecastPageTest extends TestCase
{
    use RefreshDatabase;

    private function forecastIndex(): ScoringIndex
    {
        return ScoringIndex::query()->where('code', 'RIVERINE_FLOOD_FORECAST')->firstOrFail();
    }

    private function region(): Region
    {
        return Region::query()->orderBy('region_id')->first();
    }

    private function seedForecast(Region $region, float $peak = 74.0, int $leadToPeak = 5): void
    {
        RegionForecastScore::query()->insert([
            'index_id' => $this->forecastIndex()->index_id, 'region_id' => $region->region_id,
            'forecast_issued_at' => now()->toDateString(), 'horizon_days' => 15,
            'score' => $peak, 'peak_date' => now()->addDays($leadToPeak)->toDateString(), 'lead_days_to_peak' => $leadToPeak,
            'scoring_strategy' => 'forecast_formula', 'scoring_version' => 'forecast-formula-v1',
            'breakdown' => json_encode(['daily' => [
                ['date' => now()->toDateString(), 'lead_days' => 0, 'score' => 40, 'signals' => ['RIVER_DISCHARGE' => ['raw_value' => 1600, 'normalized_score' => 40]]],
                ['date' => now()->addDays($leadToPeak)->toDateString(), 'lead_days' => $leadToPeak, 'score' => $peak, 'signals' => ['RIVER_DISCHARGE' => ['raw_value' => 2960, 'normalized_score' => $peak]]],
            ]]),
            'calculated_at' => now(),
        ]);
    }

    public function test_the_forecast_story_renders_with_the_peak_and_lead_time(): void
    {
        $user = User::factory()->create();
        $region = $this->region();
        $this->seedForecast($region, peak: 74.0, leadToPeak: 5);

        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'RIVERINE_FLOOD_FORECAST']))
            ->assertOk()
            ->assertSee('The forecast for '.$region->name)
            ->assertSee('The forecast peak')
            ->assertSee('74')
            ->assertSee('about 5 days out')
            ->assertSee('This is a forecast, not a current reading.')
            ->assertDontSee('This week in');   // the observed step 1 heading
    }

    public function test_the_probability_line_and_fan_render_when_the_ensemble_distribution_is_present(): void
    {
        $user = User::factory()->create();
        $region = $this->region();
        $this->seedForecast($region, peak: 74.0, leadToPeak: 5);

        RegionForecastScore::query()->where('index_id', $this->forecastIndex()->index_id)
            ->where('region_id', $region->region_id)
            ->update([
                'p10' => 55, 'p50' => 70, 'p90' => 88,
                'exceedance_probability' => 0.62, 'exceedance_reference' => 67, 'member_count' => 50,
                'breakdown' => json_encode([
                    'daily' => [
                        ['date' => now()->toDateString(), 'lead_days' => 0, 'score' => 40, 'signals' => ['RIVER_DISCHARGE' => ['raw_value' => 1600, 'normalized_score' => 40]]],
                        ['date' => now()->addDays(5)->toDateString(), 'lead_days' => 5, 'score' => 74, 'signals' => ['RIVER_DISCHARGE' => ['raw_value' => 2960, 'normalized_score' => 74]]],
                    ],
                    'member_daily' => [
                        ['date' => now()->toDateString(), 'lead_days' => 0, 'p10' => 35, 'p50' => 40, 'p90' => 48],
                        ['date' => now()->addDays(5)->toDateString(), 'lead_days' => 5, 'p10' => 55, 'p50' => 70, 'p90' => 88],
                    ],
                ]),
            ]);

        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'RIVERINE_FLOOD_FORECAST']))
            ->assertOk()
            ->assertSee('62% chance')
            ->assertSee('50 ensemble forecast members')
            ->assertSee('10th–90th percentile', false);
    }

    public function test_the_probability_line_is_absent_when_there_is_no_ensemble(): void
    {
        $user = User::factory()->create();
        $region = $this->region();
        $this->seedForecast($region, peak: 74.0); // no percentile columns set

        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'RIVERINE_FLOOD_FORECAST']))
            ->assertOk()
            ->assertDontSee('ensemble forecast members')
            ->assertDontSee('percentile range');
    }

    public function test_it_shows_an_honest_empty_state_when_there_is_no_forecast(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('regions.show', ['region' => $this->region(), 'index' => 'RIVERINE_FLOOD_FORECAST']))
            ->assertOk()
            ->assertSee('No river-flood forecast for');
    }

    public function test_an_observed_index_on_the_same_region_is_unaffected(): void
    {
        $user = User::factory()->create();
        $region = $this->region();
        $this->seedForecast($region, peak: 99.0);

        $flood = ScoringIndex::query()->where('code', 'FLOOD_RISK')->firstOrFail();
        RegionScore::query()->create([
            'index_id' => $flood->index_id, 'region_id' => $region->region_id,
            'period_start' => '2026-08-10', 'period_end' => '2026-08-16', 'score' => 42.0,
            'scoring_strategy' => 'formula',
            'breakdown' => [['signal_type_code' => 'RAINFALL', 'signal_type_name' => 'Rainfall', 'raw_value' => 90, 'unit' => 'mm', 'normalized_score' => 42, 'weight' => 1.0, 'contribution_to_final_score' => 42]],
            'calculated_at' => now(),
        ]);

        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'FLOOD_RISK']))
            ->assertOk()
            ->assertSee('This week in '.$region->name)
            ->assertSee('<span class="score-hero-number">42</span>', false)
            ->assertDontSee('The forecast peak')
            ->assertDontSee('This is a forecast, not a current reading.')
            ->assertDontSee('GloFAS forecast issued');
    }

    public function test_latest_score_resolves_from_the_right_lane(): void
    {
        $region = $this->region();
        $this->seedForecast($region, peak: 74.0, leadToPeak: 5);

        $forecast = LatestScore::for($region, $this->forecastIndex());
        $this->assertTrue($forecast['is_forecast']);
        $this->assertSame(74.0, $forecast['score']);
        $this->assertSame(5, $forecast['lead_days']);

        $observed = LatestScore::for($region, ScoringIndex::query()->where('code', 'FLOOD_RISK')->firstOrFail());
        $this->assertNull($observed); // no observed score seeded
    }
}
