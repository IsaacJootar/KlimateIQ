<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\PlatformSetting;
use App\Models\Region;
use App\Models\ScoringIndex;
use App\Models\ThresholdConfig;
use App\Models\User;
use App\Notifications\ThresholdBreachedNotification;
use App\Services\Sms\SmsClient;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class NotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function makeAlert(User $user): Alert
    {
        $region = Region::first();
        $index = ScoringIndex::first();

        $threshold = ThresholdConfig::query()->create([
            'user_id' => $user->id,
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'alert_type' => 'fixed_threshold',
            'comparison_operator' => '>',
            'threshold_value' => 10,
            'active' => true,
        ]);

        return Alert::query()->create([
            'threshold_config_id' => $threshold->threshold_config_id,
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'score_at_trigger' => 50,
            'threshold_value' => 10,
            'status' => 'OPEN',
            'triggered_at' => now(),
        ]);
    }

    public function test_default_preferences_only_use_the_in_app_channel(): void
    {
        $user = User::factory()->create();
        $notification = new ThresholdBreachedNotification($this->makeAlert($user));

        $this->assertSame(['database'], $notification->via($user));
    }

    public function test_email_channel_requires_both_user_preference_and_platform_setting(): void
    {
        $user = User::factory()->create();
        $user->getOrCreateDashboardPreference()->update(['alert_channels' => ['in_app', 'email']]);
        $notification = new ThresholdBreachedNotification($this->makeAlert($user));

        // Preference wants email, but the platform-wide toggle is off by default.
        $this->assertSame(['database'], $notification->via($user));

        PlatformSetting::set('email.notifications_enabled', true, 'boolean');

        $this->assertSame(['database', 'mail'], $notification->via($user));
    }

    public function test_sms_channel_requires_preference_phone_number_and_configured_client(): void
    {
        $this->mock(SmsClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
        });

        $user = User::factory()->create(['phone_number' => '+2348012345678']);
        $user->getOrCreateDashboardPreference()->update(['alert_channels' => ['in_app', 'sms']]);
        $notification = new ThresholdBreachedNotification($this->makeAlert($user));

        $channels = $notification->via($user);

        $this->assertContains('database', $channels);
        $this->assertContains(\App\Notifications\Channels\SmsChannel::class, $channels);
    }

    public function test_sms_channel_is_skipped_without_a_phone_number_even_if_preferred(): void
    {
        $this->mock(SmsClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
        });

        $user = User::factory()->create(['phone_number' => null]);
        $user->getOrCreateDashboardPreference()->update(['alert_channels' => ['in_app', 'sms']]);
        $notification = new ThresholdBreachedNotification($this->makeAlert($user));

        $this->assertSame(['database'], $notification->via($user));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
