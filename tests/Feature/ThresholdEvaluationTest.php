<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Models\PlatformSetting;
use App\Models\ThresholdConfig;
use App\Models\User;
use App\Notifications\ThresholdBreachedNotification;
use App\Services\Alerts\ThresholdEvaluationService;
use App\Services\Scoring\RegionScoringService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ThresholdEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_fixed_threshold_breach_creates_an_alert_and_notifies_the_owner(): void
    {
        Notification::fake();
        PlatformSetting::set('email.notifications_enabled', true, 'boolean');

        $user = User::factory()->create();
        $region = Region::query()->where('lga_code', 'NG-BY-YNG')->firstOrFail();
        $index = ScoringIndex::query()->where('code', 'MALARIA_RISK')->firstOrFail();
        $rainfall = SignalType::query()->where('code', 'RAINFALL')->firstOrFail();

        ThresholdConfig::query()->create([
            'user_id' => $user->id,
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'alert_type' => 'fixed_threshold',
            'comparison_operator' => '>',
            'threshold_value' => 40,
            'active' => true,
        ]);

        RegionSignal::query()->create([
            'region_id' => $region->region_id,
            'signal_type_id' => $rainfall->signal_type_id,
            'period_start' => '2026-07-26',
            'period_end' => '2026-08-01',
            'value' => 180, // normalizes to 90, comfortably above the threshold of 40
            'source' => 'test',
            'ingested_at' => now(),
        ]);

        app(RegionScoringService::class)->calculate($index, $region, Carbon::parse('2026-07-26'), Carbon::parse('2026-08-01'));

        $this->assertDatabaseHas('alerts', ['region_id' => $region->region_id, 'index_id' => $index->index_id, 'status' => 'OPEN']);
        Notification::assertSentTo($user, ThresholdBreachedNotification::class);
    }

    public function test_a_breach_still_records_an_alert_but_sends_no_email_while_notifications_are_off(): void
    {
        Notification::fake();
        // email.notifications_enabled defaults to false — no PlatformSetting row seeded.

        $user = User::factory()->create();
        $region = Region::query()->where('lga_code', 'NG-BY-YNG')->firstOrFail();
        $index = ScoringIndex::query()->where('code', 'MALARIA_RISK')->firstOrFail();
        $rainfall = SignalType::query()->where('code', 'RAINFALL')->firstOrFail();

        ThresholdConfig::query()->create([
            'user_id' => $user->id,
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'alert_type' => 'fixed_threshold',
            'comparison_operator' => '>',
            'threshold_value' => 40,
            'active' => true,
        ]);

        RegionSignal::query()->create([
            'region_id' => $region->region_id,
            'signal_type_id' => $rainfall->signal_type_id,
            'period_start' => '2026-07-26',
            'period_end' => '2026-08-01',
            'value' => 180,
            'source' => 'test',
            'ingested_at' => now(),
        ]);

        app(RegionScoringService::class)->calculate($index, $region, Carbon::parse('2026-07-26'), Carbon::parse('2026-08-01'));

        $this->assertDatabaseHas('alerts', ['region_id' => $region->region_id, 'index_id' => $index->index_id, 'status' => 'OPEN']);
        Notification::assertNothingSent();
    }

    public function test_a_threshold_below_the_breach_value_does_not_alert(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $region = Region::query()->where('lga_code', 'NG-BY-YNG')->firstOrFail();
        $index = ScoringIndex::query()->where('code', 'MALARIA_RISK')->firstOrFail();
        $rainfall = SignalType::query()->where('code', 'RAINFALL')->firstOrFail();

        ThresholdConfig::query()->create([
            'user_id' => $user->id,
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'alert_type' => 'fixed_threshold',
            'comparison_operator' => '>',
            'threshold_value' => 95,
            'active' => true,
        ]);

        RegionSignal::query()->create([
            'region_id' => $region->region_id,
            'signal_type_id' => $rainfall->signal_type_id,
            'period_start' => '2026-07-26',
            'period_end' => '2026-08-01',
            'value' => 20, // normalizes to 10, well under the threshold of 95
            'source' => 'test',
            'ingested_at' => now(),
        ]);

        app(RegionScoringService::class)->calculate($index, $region, Carbon::parse('2026-07-26'), Carbon::parse('2026-08-01'));

        $this->assertDatabaseMissing('alerts', ['region_id' => $region->region_id, 'index_id' => $index->index_id]);
        Notification::assertNothingSent();
    }

    public function test_an_already_open_alert_is_not_duplicated(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $region = Region::query()->where('lga_code', 'NG-BY-YNG')->firstOrFail();
        $index = ScoringIndex::query()->where('code', 'MALARIA_RISK')->firstOrFail();

        $threshold = ThresholdConfig::query()->create([
            'user_id' => $user->id,
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'alert_type' => 'fixed_threshold',
            'comparison_operator' => '>',
            'threshold_value' => 10,
            'active' => true,
        ]);

        $evaluator = app(ThresholdEvaluationService::class);
        $evaluator->evaluateForIndex($index->index_id, $region->region_id, '2026-07-26', 50.0);
        $evaluator->evaluateForIndex($index->index_id, $region->region_id, '2026-08-02', 60.0);

        $this->assertSame(1, \App\Models\Alert::query()->where('threshold_config_id', $threshold->threshold_config_id)->count());
    }

    public function test_signal_level_threshold_is_independent_of_index_thresholds(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $region = Region::query()->where('lga_code', 'NG-LA-IKJ')->firstOrFail();
        $rainfall = SignalType::query()->where('code', 'RAINFALL')->firstOrFail();

        ThresholdConfig::query()->create([
            'user_id' => $user->id,
            'region_id' => $region->region_id,
            'signal_type_id' => $rainfall->signal_type_id,
            'alert_type' => 'fixed_threshold',
            'comparison_operator' => '>',
            'threshold_value' => 30,
            'active' => true,
        ]);

        app(ThresholdEvaluationService::class)->evaluateForSignal($rainfall->signal_type_id, $region->region_id, '2026-07-26', 62.12);

        $this->assertDatabaseHas('alerts', [
            'region_id' => $region->region_id,
            'signal_type_id' => $rainfall->signal_type_id,
            'index_id' => null,
        ]);
    }
}
