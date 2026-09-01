<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Region;
use App\Models\ScoringIndex;
use App\Models\ThresholdConfig;
use App\Models\User;
use App\Notifications\ThresholdBreachedNotification;
use App\Services\Alerts\ThresholdEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T4 M4 — forecast-breach alerts. A threshold on a forecast index fires on the
 * forecast peak, clearly labelled as a forecast, and clears itself when the forecast recedes.
 */
class ForecastAlertTest extends TestCase
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

    private function threshold(string $type = 'fixed_threshold', float $value = 70, ?string $op = '>='): ThresholdConfig
    {
        return ThresholdConfig::query()->create([
            'user_id' => $this->user->id,
            'region_id' => $this->region->region_id,
            'index_id' => $this->index->index_id,
            'alert_type' => $type,
            'comparison_operator' => $op,
            'threshold_value' => $type === 'fixed_threshold' ? $value : null,
            'anomaly_stddev_multiplier' => $type === 'anomaly' ? 2.0 : null,
            'active' => true,
        ]);
    }

    private function evaluate(?float $peak, string $peakDate = '2026-09-08', int $lead = 5): void
    {
        app(ThresholdEvaluationService::class)->evaluateForForecast(
            $this->index->index_id, $this->region->region_id, $peak, $peakDate, $lead,
        );
    }

    public function test_a_forecast_peak_over_the_threshold_opens_a_forecast_labelled_alert(): void
    {
        Notification::fake();
        $config = $this->threshold(value: 70);

        $this->evaluate(peak: 82, lead: 5);

        $alert = Alert::query()->where('threshold_config_id', $config->threshold_config_id)->first();
        $this->assertNotNull($alert);
        $this->assertTrue($alert->is_forecast);
        $this->assertSame(5, $alert->forecast_lead_days);
        $this->assertSame('2026-09-08', $alert->forecast_target_date->toDateString());

        Notification::assertSentTo($this->user, ThresholdBreachedNotification::class, function ($n) {
            $db = $n->toDatabase($this->user);

            return str_contains($db['body'], 'FORECAST')
                && str_contains($db['body'], 'forecast, not a current reading');
        });
    }

    public function test_a_peak_below_the_threshold_opens_nothing(): void
    {
        Notification::fake();
        $this->threshold(value: 70);

        $this->evaluate(peak: 40);

        $this->assertSame(0, Alert::query()->count());
        Notification::assertNothingSent();
    }

    public function test_the_open_alert_follows_the_forecast_without_re_notifying(): void
    {
        Notification::fake();
        $config = $this->threshold(value: 70);

        $this->evaluate(peak: 82, lead: 6, peakDate: '2026-09-09');
        $this->evaluate(peak: 88, lead: 3, peakDate: '2026-09-06');

        $this->assertSame(1, Alert::query()->where('threshold_config_id', $config->threshold_config_id)->count());
        $alert = Alert::query()->first();
        $this->assertEquals(88, $alert->score_at_trigger);
        $this->assertSame(3, $alert->forecast_lead_days);
        Notification::assertSentToTimes($this->user, ThresholdBreachedNotification::class, 1);
    }

    public function test_the_alert_auto_resolves_when_the_forecast_recedes(): void
    {
        Notification::fake();
        $this->threshold(value: 70);

        $this->evaluate(peak: 82);
        $this->assertSame('OPEN', Alert::query()->first()->status);

        $this->evaluate(peak: 55); // next run, forecast has dropped
        $this->assertSame('RESOLVED', Alert::query()->first()->fresh()->status);
    }

    public function test_the_alert_auto_resolves_once_its_target_date_has_passed(): void
    {
        Notification::fake();
        $this->threshold(value: 70);

        $this->evaluate(peak: 82, peakDate: today()->addDays(4)->toDateString());
        $this->assertSame('OPEN', Alert::query()->first()->status);

        // A later run whose peak now sits in the past — the window has closed.
        $this->evaluate(peak: 82, peakDate: today()->subDay()->toDateString());
        $this->assertSame('RESOLVED', Alert::query()->first()->status);
    }

    public function test_an_anomaly_threshold_on_a_forecast_index_is_ignored(): void
    {
        Notification::fake();
        $this->threshold(type: 'anomaly');

        $this->evaluate(peak: 99);

        $this->assertSame(0, Alert::query()->count());
    }

    public function test_a_forecast_score_never_triggers_an_observed_index_threshold_path(): void
    {
        Notification::fake();
        // A threshold on FLOOD_RISK (observed) — the forecast path must not touch it.
        $flood = ScoringIndex::query()->where('code', 'FLOOD_RISK')->firstOrFail();
        ThresholdConfig::query()->create([
            'user_id' => $this->user->id, 'region_id' => $this->region->region_id, 'index_id' => $flood->index_id,
            'alert_type' => 'fixed_threshold', 'comparison_operator' => '>=', 'threshold_value' => 10, 'active' => true,
        ]);

        app(ThresholdEvaluationService::class)->evaluateForForecast($flood->index_id, $this->region->region_id, 99, '2026-09-08', 5);

        $this->assertSame(0, Alert::query()->count());
    }
}
