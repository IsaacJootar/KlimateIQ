<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionForecastScore;
use App\Models\RegionForecastSignal;
use App\Models\RegionScore;
use App\Models\RegionScoringConfig;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T4 M2 — scores:forecast owns forecast indices; scores:calculate leaves them alone.
 */
class CalculateForecastScoresCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedForecastIndex(): ScoringIndex
    {
        $dischargeId = SignalType::query()->where('code', 'RIVER_DISCHARGE')->value('signal_type_id');
        $index = ScoringIndex::query()->create(['code' => 'TEST_FF', 'name' => 'Test FF', 'is_forecast' => true]);
        RegionScoringConfig::query()->create(['index_id' => $index->index_id, 'region_id' => null, 'signal_type_id' => $dischargeId, 'weight' => 1.0, 'enabled' => true]);
        foreach (['MIN' => 0, 'MAX' => 1000] as $s => $v) {
            ScoringCalibrationParameter::query()->create(['index_id' => $index->index_id, 'region_id' => null, 'parameter_key' => "RIVER_DISCHARGE_{$s}", 'parameter_value' => $v]);
        }

        return $index;
    }

    private function activeRegionWithForecast(): Region
    {
        $region = Region::query()->orderBy('region_id')->first();
        $region->subscribers()->create(['user_id' => User::factory()->create()->id]);
        RegionForecastSignal::query()->create([
            'region_id' => $region->region_id,
            'signal_type_id' => SignalType::query()->where('code', 'RIVER_DISCHARGE')->value('signal_type_id'),
            'forecast_issued_at' => now()->toDateString(),
            'target_date' => now()->addDays(2)->toDateString(),
            'lead_days' => 2, 'value' => 800, 'source' => 'test', 'ingested_at' => now(),
        ]);

        return $region;
    }

    public function test_it_scores_forecast_indices_for_active_regions(): void
    {
        $index = $this->seedForecastIndex();
        $region = $this->activeRegionWithForecast();

        $this->artisan('scores:forecast')->assertSuccessful();

        $this->assertEquals(80.0, RegionForecastScore::query()->where('index_id', $index->index_id)->where('region_id', $region->region_id)->value('score'));
    }

    public function test_scores_calculate_skips_forecast_indices(): void
    {
        $index = $this->seedForecastIndex();
        $region = $this->activeRegionWithForecast();

        $this->artisan('scores:calculate', ['--region' => $region->region_id])->assertSuccessful();

        // No observed row for the forecast index, and no forecast row (scores:calculate doesn't own it).
        $this->assertSame(0, RegionScore::query()->where('index_id', $index->index_id)->count());
        $this->assertSame(0, RegionForecastScore::query()->where('index_id', $index->index_id)->count());
    }

    public function test_the_forecast_schedule_is_registered(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($e) => str_contains($e->command ?? '', 'scores:forecast')));
        $this->assertTrue($events->contains(fn ($e) => str_contains($e->command ?? '', 'signals:ingest-forecast')));
    }
}
