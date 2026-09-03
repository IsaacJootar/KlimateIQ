<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Region;
use App\Models\RegionForecastScore;
use App\Models\ScoringIndex;
use App\Models\ThresholdConfig;
use App\Models\User;
use App\Notifications\ThresholdBreachedNotification;
use App\Services\Alerts\ThresholdEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T5 M3 — the probability threshold rule. Fires when the ensemble forecast gives
 * at least `probability_threshold` percent chance of the index peak reaching `threshold_value`
 * within the horizon; follows and auto-resolves like the T4 forecast alert.
 */
class ProbabilityThresholdAlertTest extends TestCase
{
    use RefreshDatabase;

    private ScoringIndex $index;

    private Region $region;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = ScoringIndex::query()->where('code', 'RIVERINE_FLOOD_FORECAST')->firstOrFail();
        $this->region = Region::query()->orderBy('region_id')->first();
        $this->user = User::factory()->create();
        $this->user->regionSubscriptions()->create(['region_id' => $this->region->region_id]);
    }

    /** @param  list<float>  $memberPeaks */
    private function forecastRow(array $memberPeaks, float $control = 50, string $peakDate = '2026-09-12'): void
    {
        sort($memberPeaks);
        $n = count($memberPeaks);
        $exceed = count(array_filter($memberPeaks, fn ($p) => $p >= 67)) / $n;

        RegionForecastScore::query()->upsert([[
            'index_id' => $this->index->index_id, 'region_id' => $this->region->region_id,
            'forecast_issued_at' => today()->toDateString(), 'horizon_days' => 14,
            'score' => $control, 'p10' => $memberPeaks[(int) ($n * 0.1)], 'p50' => $memberPeaks[(int) ($n * 0.5)],
            'p90' => $memberPeaks[(int) ($n * 0.9)], 'exceedance_probability' => $exceed, 'exceedance_reference' => 67,
            'member_count' => $n, 'peak_date' => $peakDate, 'lead_days_to_peak' => 9,
            'scoring_strategy' => 'forecast_formula', 'breakdown' => json_encode(['members' => $memberPeaks]),
            'calculated_at' => now(),
        ]], ['index_id', 'region_id'], ['score', 'p50', 'breakdown', 'peak_date']);
    }

    private function probabilityThreshold(float $level = 67, float $pct = 60): ThresholdConfig
    {
        return ThresholdConfig::query()->create([
            'user_id' => $this->user->id, 'region_id' => $this->region->region_id, 'index_id' => $this->index->index_id,
            'alert_type' => 'forecast_probability', 'comparison_operator' => '>=',
            'threshold_value' => $level, 'probability_threshold' => $pct, 'watch_forecast' => true, 'active' => true,
        ]);
    }

    private function evaluate(float $peak = 80, string $peakDate = '2026-09-12', int $lead = 9): void
    {
        app(ThresholdEvaluationService::class)->evaluateForForecast(
            $this->index->index_id, $this->region->region_id, $peak, $peakDate, $lead,
        );
    }

    public function test_it_fires_when_the_member_share_meets_the_probability_and_carries_it(): void
    {
        Notification::fake();
        $config = $this->probabilityThreshold(level: 67, pct: 60);
        // 40 of 50 members peak at/above 67 → 80% ≥ 60%.
        $this->forecastRow(array_merge(array_fill(0, 40, 72.0), array_fill(0, 10, 30.0)));

        $this->evaluate();

        $alert = Alert::query()->where('threshold_config_id', $config->threshold_config_id)->first();
        $this->assertNotNull($alert);
        $this->assertTrue($alert->is_forecast);
        $this->assertEqualsWithDelta(0.8, (float) $alert->forecast_probability, 0.001);

        Notification::assertSentTo($this->user, ThresholdBreachedNotification::class, function ($n) {
            $db = $n->toDatabase($this->user);

            return str_contains($db['body'], '80%') && str_contains($db['title'], '80%');
        });
    }

    public function test_it_does_not_fire_when_the_share_is_below_the_probability(): void
    {
        Notification::fake();
        $this->probabilityThreshold(level: 67, pct: 80);
        // 40/50 = 80%? use 35/50 = 70% < 80%.
        $this->forecastRow(array_merge(array_fill(0, 35, 72.0), array_fill(0, 15, 30.0)));

        $this->evaluate();

        $this->assertSame(0, Alert::query()->count());
        Notification::assertNothingSent();
    }

    public function test_it_auto_resolves_when_a_later_forecast_drops_the_share(): void
    {
        Notification::fake();
        $this->probabilityThreshold(level: 67, pct: 60);

        $this->forecastRow(array_merge(array_fill(0, 40, 72.0), array_fill(0, 10, 30.0)));
        $this->evaluate();
        $this->assertSame('OPEN', Alert::query()->first()->status);

        $this->forecastRow(array_merge(array_fill(0, 10, 72.0), array_fill(0, 40, 30.0))); // 20%
        $this->evaluate();
        $this->assertSame('RESOLVED', Alert::query()->first()->fresh()->status);
    }

    public function test_the_store_route_creates_a_probability_rule_on_a_forecast_capable_index(): void
    {
        $response = $this->actingAs($this->user)->post(route('thresholds.store'), [
            'region_id' => $this->region->region_id,
            'target_type' => 'index',
            'index_id' => $this->index->index_id,
            'alert_type' => 'forecast_probability',
            'prob_threshold_value' => 67,
            'probability_threshold' => 55,
        ]);

        $response->assertRedirect();
        $config = ThresholdConfig::query()->where('user_id', $this->user->id)->first();
        $this->assertSame('forecast_probability', $config->alert_type);
        $this->assertEquals(67, (float) $config->threshold_value);
        $this->assertEquals(55, (float) $config->probability_threshold);
        $this->assertTrue($config->watch_forecast);
        $this->assertSame('>=', $config->comparison_operator);
    }

    public function test_the_store_route_rejects_a_probability_rule_on_an_index_with_no_forecast(): void
    {
        $noForecast = ScoringIndex::query()->where('is_forecast', false)
            ->whereDoesntHave('scoringConfigs', fn ($q) => $q->whereHas('signalType', fn ($s) => $s->whereIn('code', ['RAINFALL', 'TEMPERATURE', 'RIVER_DISCHARGE'])))
            ->firstOrFail();

        $response = $this->actingAs($this->user)->post(route('thresholds.store'), [
            'region_id' => $this->region->region_id,
            'target_type' => 'index',
            'index_id' => $noForecast->index_id,
            'alert_type' => 'forecast_probability',
            'prob_threshold_value' => 67,
            'probability_threshold' => 55,
        ]);

        $response->assertSessionHasErrors('alert_type');
        $this->assertSame(0, ThresholdConfig::query()->count());
    }

    public function test_a_plain_fixed_threshold_on_the_same_index_is_unaffected(): void
    {
        Notification::fake();
        $this->probabilityThreshold(level: 67, pct: 90); // won't fire
        $fixed = ThresholdConfig::query()->create([
            'user_id' => $this->user->id, 'region_id' => $this->region->region_id, 'index_id' => $this->index->index_id,
            'alert_type' => 'fixed_threshold', 'comparison_operator' => '>=', 'threshold_value' => 70, 'active' => true,
        ]);
        $this->forecastRow(array_merge(array_fill(0, 20, 72.0), array_fill(0, 30, 30.0))); // 40% < 90%

        $this->evaluate(peak: 80); // fixed fires on the peak

        $alerts = Alert::query()->get();
        $this->assertCount(1, $alerts);
        $this->assertSame($fixed->threshold_config_id, $alerts->first()->threshold_config_id);
        $this->assertNull($alerts->first()->forecast_probability);
    }
}
