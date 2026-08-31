<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\SignalType;
use App\Models\User;
use App\Models\UserRegionSubscription;
use App\Support\IngestionWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillHistoricalSignalsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function activeRegion(): Region
    {
        $region = Region::query()->first();
        $region->update(['latitude' => 9.0765, 'longitude' => 7.3986]);
        UserRegionSubscription::create(['user_id' => User::factory()->create()->id, 'region_id' => $region->region_id]);

        return $region->fresh();
    }

    public function test_it_backfills_rainfall_and_temperature_for_each_requested_period(): void
    {
        $region = $this->activeRegion();

        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response([
                'daily' => ['precipitation_sum' => [1.0, 2.0], 'temperature_2m_mean' => [20.0, 22.0]],
            ], 200),
        ]);

        $this->artisan('signals:backfill-history', ['--periods' => 3])->assertSuccessful();

        $rainfallType = SignalType::where('code', 'RAINFALL')->firstOrFail();
        $temperatureType = SignalType::where('code', 'TEMPERATURE')->firstOrFail();

        $this->assertSame(3, RegionSignal::where('region_id', $region->region_id)->where('signal_type_id', $rainfallType->signal_type_id)->count());
        $this->assertSame(3, RegionSignal::where('region_id', $region->region_id)->where('signal_type_id', $temperatureType->signal_type_id)->count());
    }

    public function test_it_never_overwrites_a_period_that_already_has_real_data(): void
    {
        $region = $this->activeRegion();
        $rainfallType = SignalType::where('code', 'RAINFALL')->firstOrFail();
        [$liveStart] = IngestionWindow::lastComplete();
        $backfillPeriodEnd = $liveStart->copy()->subDay();
        $backfillPeriodStart = $backfillPeriodEnd->copy()->subDays(6);

        RegionSignal::create([
            'region_id' => $region->region_id,
            'signal_type_id' => $rainfallType->signal_type_id,
            'period_start' => $backfillPeriodStart->toDateString(),
            'period_end' => $backfillPeriodEnd->toDateString(),
            'value' => 999,
            'ingested_at' => now(),
            'source' => 'NASA POWER (PRECTOTCORR)',
        ]);

        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response([
                'daily' => ['precipitation_sum' => [1.0], 'temperature_2m_mean' => [20.0]],
            ], 200),
        ]);

        $this->artisan('signals:backfill-history', ['--periods' => 1])->assertSuccessful();

        $signal = RegionSignal::where('region_id', $region->region_id)->where('signal_type_id', $rainfallType->signal_type_id)->first();
        $this->assertEquals(999, $signal->value);
        $this->assertSame('NASA POWER (PRECTOTCORR)', $signal->source);
    }

    public function test_it_labels_backfilled_signals_honestly(): void
    {
        $this->activeRegion();

        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response([
                'daily' => ['precipitation_sum' => [3.0, 4.0], 'temperature_2m_mean' => [21.0, 23.0]],
            ], 200),
        ]);

        $this->artisan('signals:backfill-history', ['--periods' => 1])->assertSuccessful();

        $signal = RegionSignal::first();
        $this->assertSame('Open-Meteo (ERA5 historical backfill)', $signal->source);
        $this->assertTrue($signal->raw_metadata['backfill']);
    }

    public function test_rainfall_sums_while_temperature_averages(): void
    {
        $region = $this->activeRegion();

        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response([
                'daily' => ['precipitation_sum' => [2.0, 4.0], 'temperature_2m_mean' => [20.0, 24.0]],
            ], 200),
        ]);

        $this->artisan('signals:backfill-history', ['--periods' => 1])->assertSuccessful();

        $rainfallType = SignalType::where('code', 'RAINFALL')->firstOrFail();
        $temperatureType = SignalType::where('code', 'TEMPERATURE')->firstOrFail();

        $rainfall = RegionSignal::where('region_id', $region->region_id)->where('signal_type_id', $rainfallType->signal_type_id)->first();
        $temperature = RegionSignal::where('region_id', $region->region_id)->where('signal_type_id', $temperatureType->signal_type_id)->first();

        $this->assertEquals(6.0, $rainfall->value);
        $this->assertEquals(22.0, $temperature->value);
    }

    public function test_dormant_regions_are_not_backfilled(): void
    {
        Region::query()->update(['latitude' => 9.0, 'longitude' => 7.0]);

        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response([
                'daily' => ['precipitation_sum' => [1.0], 'temperature_2m_mean' => [20.0]],
            ], 200),
        ]);

        $this->artisan('signals:backfill-history', ['--periods' => 1])->assertSuccessful();

        $this->assertSame(0, RegionSignal::count());
    }
}
