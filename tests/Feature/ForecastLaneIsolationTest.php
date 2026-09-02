<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Region;
use App\Models\RegionForecastScore;
use App\Models\RegionForecastSignal;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Models\User;
use App\Services\Alerts\ThresholdEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T4 — the whole point of separate tables: forecast data must never surface in
 * an observed-data read. If this fails, a forecast row has leaked into an observed query.
 */
class ForecastLaneIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function region(): Region
    {
        return Region::query()->orderBy('region_id')->first();
    }

    private function seedObservedFloodScore(Region $region, float $score): void
    {
        $index = ScoringIndex::query()->where('code', 'FLOOD_RISK')->firstOrFail();

        RegionScore::query()->create([
            'index_id' => $index->index_id, 'region_id' => $region->region_id,
            'period_start' => '2026-08-10', 'period_end' => '2026-08-16',
            'score' => $score, 'scoring_strategy' => 'formula',
            'breakdown' => [['signal_type_code' => 'RAINFALL', 'signal_type_name' => 'Rainfall', 'raw_value' => 100, 'unit' => 'mm', 'normalized_score' => $score, 'weight' => 1.0, 'contribution_to_final_score' => $score]],
            'calculated_at' => now(),
        ]);
    }

    private function seedForecastData(Region $region, float $signalValue, float $peakScore): void
    {
        $dischargeId = SignalType::query()->where('code', 'RIVER_DISCHARGE')->value('signal_type_id');
        $ff = ScoringIndex::query()->firstOrCreate(['code' => 'FF_ISOLATION'], ['name' => 'FF', 'is_forecast' => true]);

        RegionForecastSignal::query()->where('region_id', $region->region_id)->delete();
        foreach ([1, 2, 3] as $lead) {
            RegionForecastSignal::query()->create([
                'region_id' => $region->region_id, 'signal_type_id' => $dischargeId,
                'forecast_issued_at' => now()->toDateString(), 'target_date' => now()->addDays($lead)->toDateString(),
                'lead_days' => $lead, 'value' => $signalValue, 'source' => 'test', 'ingested_at' => now(),
            ]);
        }
        RegionForecastScore::query()->upsert([[
            'index_id' => $ff->index_id, 'region_id' => $region->region_id, 'forecast_issued_at' => now()->toDateString(),
            'horizon_days' => 3, 'score' => $peakScore, 'peak_date' => now()->addDays(2)->toDateString(), 'lead_days_to_peak' => 2,
            'scoring_strategy' => 'forecast_formula', 'breakdown' => '{}', 'calculated_at' => now(),
        ]], ['index_id', 'region_id'], ['score']);
    }

    public function test_an_observed_region_page_never_shows_forecast_numbers(): void
    {
        $user = User::factory()->create();
        $region = $this->region();
        $this->seedObservedFloodScore($region, 55.0);
        $this->seedForecastData($region, signalValue: 9999, peakScore: 88.0);

        $response = $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'FLOOD_RISK']));

        $response->assertOk();
        $response->assertSee('55');                        // the observed score
        $response->assertDontSee('9999');                  // the forecast signal value
        $response->assertDontSee('88.00', false);          // the forecast index score
    }

    public function test_updating_the_forecast_does_not_change_what_the_observed_page_renders(): void
    {
        $user = User::factory()->create();
        $region = $this->region();
        $this->seedObservedFloodScore($region, 55.0);
        $this->seedForecastData($region, signalValue: 100, peakScore: 10.0);

        $before = $this->body($user, $region);

        $this->seedForecastData($region, signalValue: 9999, peakScore: 99.0);
        $after = $this->body($user, $region);

        $this->assertSame($before, $after);
    }

    /** The page's <main>, normalised past per-request tokens and once-per-process Livewire chrome. */
    private function body(User $user, Region $region): string
    {
        $html = $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'FLOOD_RISK']))->getContent();
        $main = substr($html, (int) strpos($html, '<main'), (int) strpos($html, '</main>') - (int) strpos($html, '<main'));
        $main = preg_replace('/value="[A-Za-z0-9]{40}"/', 'value="TOKEN"', $main);
        // "Calculated N seconds ago" drifts between the two renders on a slow run — not a leak.
        $main = preg_replace('/Calculated .*? ago/', 'Calculated SOME time ago', $main);

        return (string) preg_replace('/\s+/', ' ', $main);
    }

    public function test_the_anomaly_baseline_only_sees_observed_signals(): void
    {
        $region = $this->region();
        $dischargeId = SignalType::query()->where('code', 'RIVER_DISCHARGE')->value('signal_type_id');

        foreach (['2026-08-03' => 100, '2026-08-10' => 110] as $start => $value) {
            $region->signals()->create([
                'signal_type_id' => $dischargeId, 'period_start' => $start,
                'period_end' => Carbon::parse($start)->addDays(6)->toDateString(),
                'value' => $value, 'source' => 'test', 'ingested_at' => now(),
            ]);
        }
        $this->seedForecastData($region, signalValue: 9999, peakScore: 99.0);

        app(ThresholdEvaluationService::class)->evaluateForSignal($dischargeId, $region->region_id, '2026-08-17', 120.0);

        $this->assertSame(0, Alert::query()->count());
        $this->assertSame(2, $region->signals()->where('signal_type_id', $dischargeId)->count());
    }
}
