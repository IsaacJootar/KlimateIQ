<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionForecastScore;
use App\Models\RegionForecastSignal;
use App\Models\RegionScore;
use App\Models\RegionSignal;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Models\User;
use App\Services\Ingestion\RainfallForecastService;
use App\Services\Ingestion\TemperatureForecastService;
use App\Services\Scoring\ForecastScoringStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T4 follow-up — forward-scoring the observed indices (Flood Risk on forecast
 * rainfall, Heat Stress on forecast temperature) off the Open-Meteo Forecast API. Everything
 * still lands in the forecast lane; the observed score and story are untouched.
 */
class ForwardScoringObservedIndicesTest extends TestCase
{
    use RefreshDatabase;

    private function region(): Region
    {
        $region = Region::query()->orderBy('region_id')->first();
        $region->update(['latitude' => 6.6, 'longitude' => 3.35]);

        return $region->fresh();
    }

    private function signalId(string $code): int
    {
        return SignalType::query()->where('code', $code)->value('signal_type_id');
    }

    private function fakeForecast(string $variable, array $values): void
    {
        Http::fake(['api.open-meteo.com/v1/forecast*' => function ($request) use ($variable, $values) {
            $start = Carbon::parse($request['start_date']);
            $time = [];
            for ($i = 0; $i < count($values); $i++) {
                $time[] = $start->copy()->addDays($i)->toDateString();
            }

            return Http::response(['daily' => ['time' => $time, $variable => $values]], 200);
        }]);
    }

    public function test_the_rainfall_forecast_service_writes_to_the_forecast_lane(): void
    {
        $region = $this->region();
        $this->fakeForecast('precipitation_sum', [10.0, 20.0, 30.0]);

        $rain = app(RainfallForecastService::class)->ingestForecastForRegion($region, Carbon::parse('2026-09-01'), 2);

        $this->assertCount(3, $rain);
        $this->assertSame(0, RegionSignal::query()->count(), 'forecast data must not touch region_signals');
        $this->assertSame('RAINFALL', $rain->first()->signalType->code);
    }

    public function test_the_temperature_forecast_service_writes_to_the_forecast_lane(): void
    {
        $region = $this->region();
        $this->fakeForecast('temperature_2m_mean', [31.0, 32.0, 33.0]);

        $temp = app(TemperatureForecastService::class)->ingestForecastForRegion($region, Carbon::parse('2026-09-01'), 2);

        $this->assertCount(3, $temp);
        $this->assertSame('TEMPERATURE', $temp->first()->signalType->code);
    }

    public function test_flood_risk_is_forward_scored_from_forecast_rainfall_with_observed_standing_water_held_flat(): void
    {
        $region = $this->region();
        $flood = ScoringIndex::query()->where('code', 'FLOOD_RISK')->firstOrFail();
        $issued = Carbon::parse('2026-09-01');

        // Observed standing water on file — no forecast series, so it's held flat.
        $region->signals()->create([
            'signal_type_id' => $this->signalId('STANDING_WATER'),
            'period_start' => '2026-08-17', 'period_end' => '2026-08-23',
            'value' => 50, 'source' => 'test', 'ingested_at' => now(),
        ]);

        // Forecast rainfall rising toward a peak on day 3.
        foreach ([0 => 20, 1 => 60, 2 => 120, 3 => 180] as $lead => $mm) {
            RegionForecastSignal::query()->create([
                'region_id' => $region->region_id, 'signal_type_id' => $this->signalId('RAINFALL'),
                'forecast_issued_at' => $issued->toDateString(), 'target_date' => $issued->copy()->addDays($lead)->toDateString(),
                'lead_days' => $lead, 'value' => $mm, 'source' => 'test', 'ingested_at' => now(),
            ]);
        }

        $result = app(ForecastScoringStrategy::class)->score($flood, $region, $issued);

        $this->assertNotNull($result->score);
        $this->assertSame(3, $result->leadDaysToPeak); // wettest day
        // Every daily breakdown carries both the forecast rainfall and the held-flat standing water.
        $peakDay = collect($result->breakdown['daily'])->firstWhere('lead_days', 3);
        $this->assertArrayHasKey('RAINFALL', $peakDay['signals']);
        $this->assertArrayHasKey('STANDING_WATER', $peakDay['signals']);
        $this->assertSame(50.0, $peakDay['signals']['STANDING_WATER']['raw_value']);
    }

    public function test_an_index_with_no_forecastable_signal_is_not_forward_scored(): void
    {
        $region = $this->region();
        // Respiratory Risk weights PM / ozone / NO2 / dust — none have a forecast source.
        $respiratory = ScoringIndex::query()->where('code', 'RESPIRATORY_RISK')->firstOrFail();

        $result = app(ForecastScoringStrategy::class)->score($respiratory, $region, Carbon::now());

        $this->assertNull($result->score);
    }

    public function test_scores_forecast_covers_the_forwardable_observed_indices(): void
    {
        $region = $this->region();
        $issued = Carbon::now()->startOfDay();

        $region->signals()->create([
            'signal_type_id' => $this->signalId('STANDING_WATER'),
            'period_start' => now()->subWeek()->toDateString(), 'period_end' => now()->subDays(1)->toDateString(),
            'value' => 40, 'source' => 'test', 'ingested_at' => now(),
        ]);
        foreach ([0, 1, 2] as $lead) {
            RegionForecastSignal::query()->create([
                'region_id' => $region->region_id, 'signal_type_id' => $this->signalId('RAINFALL'),
                'forecast_issued_at' => $issued->toDateString(), 'target_date' => $issued->copy()->addDays($lead)->toDateString(),
                'lead_days' => $lead, 'value' => 90 + $lead * 20, 'source' => 'test', 'ingested_at' => now(),
            ]);
        }

        $this->artisan('scores:forecast', ['--region' => $region->region_id])->assertSuccessful();

        $flood = ScoringIndex::query()->where('code', 'FLOOD_RISK')->value('index_id');
        $this->assertTrue(
            RegionForecastScore::query()->where('index_id', $flood)->where('region_id', $region->region_id)->exists(),
            'Flood Risk should have a forecast score row',
        );
        // Observed lane untouched.
        $this->assertSame(0, RegionScore::query()->count());
    }

    public function test_the_observed_flood_page_shows_the_real_forecast_in_where_its_heading(): void
    {
        $user = User::factory()->create();
        $region = $this->region();
        $flood = ScoringIndex::query()->where('code', 'FLOOD_RISK')->firstOrFail();

        RegionScore::query()->create([
            'index_id' => $flood->index_id, 'region_id' => $region->region_id,
            'period_start' => '2026-08-10', 'period_end' => '2026-08-16', 'score' => 45, 'scoring_strategy' => 'formula',
            'breakdown' => [['signal_type_code' => 'RAINFALL', 'signal_type_name' => 'Rainfall', 'raw_value' => 90, 'unit' => 'mm', 'normalized_score' => 45, 'weight' => 1.0, 'contribution_to_final_score' => 45]],
            'calculated_at' => now(),
        ]);

        RegionForecastScore::query()->insert([
            'index_id' => $flood->index_id, 'region_id' => $region->region_id,
            'forecast_issued_at' => now()->toDateString(), 'horizon_days' => 14,
            'score' => 78.0, 'peak_date' => now()->addDays(4)->toDateString(), 'lead_days_to_peak' => 4,
            'scoring_strategy' => 'forecast_formula', 'scoring_version' => 'forecast-formula-v1',
            'breakdown' => json_encode(['daily' => [
                ['date' => now()->toDateString(), 'lead_days' => 0, 'score' => 44, 'signals' => []],
                ['date' => now()->addDays(4)->toDateString(), 'lead_days' => 4, 'score' => 78, 'signals' => []],
            ]]),
            'calculated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'FLOOD_RISK']));

        $response->assertOk();
        $response->assertSee('45');                                  // observed score still there
        $response->assertSee('Forecast to reach 78');                // the real forecast line
        $response->assertDontSee('If it keeps moving at this rate'); // the naive projection is gone
    }
}
