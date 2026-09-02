<?php

namespace Tests\Feature;

use App\Events\RegionSignalIngested;
use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\SignalType;
use App\Models\User;
use App\Models\UserRegionSubscription;
use App\Support\IngestionWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T4 — front-loads a year of weekly river-discharge history so per-LGA
 * calibration has a full seasonal record to work from.
 */
class BackfillRiverDischargeCommandTest extends TestCase
{
    use RefreshDatabase;

    private function riverRegion(): Region
    {
        $region = Region::query()->orderBy('region_id')->first();
        $region->update(['latitude' => 7.8, 'longitude' => 6.74]);
        UserRegionSubscription::create(['user_id' => User::factory()->create()->id, 'region_id' => $region->region_id]);

        return $region->fresh();
    }

    /** Every request answered with a full daily series over its start_date..end_date, value = day-of-year. */
    private function fakeFullSeries(): void
    {
        Http::fake(['flood-api.open-meteo.com/*' => function ($request) {
            $start = Carbon::parse($request['start_date']);
            $end = Carbon::parse($request['end_date']);
            $time = [];
            $discharge = [];
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $time[] = $d->toDateString();
                $discharge[] = (float) $d->dayOfYear;
            }

            return Http::response(['daily' => ['time' => $time, 'river_discharge' => $discharge]], 200);
        }]);
    }

    private function dischargeId(): int
    {
        return SignalType::query()->where('code', 'RIVER_DISCHARGE')->value('signal_type_id');
    }

    public function test_it_writes_one_weekly_mean_row_per_backfilled_period(): void
    {
        Event::fake([RegionSignalIngested::class]);
        $region = $this->riverRegion();
        $this->fakeFullSeries();

        $this->artisan('signals:backfill-discharge', ['--weeks' => 6, '--region' => $region->region_id])
            ->assertSuccessful();

        $rows = RegionSignal::query()
            ->where('region_id', $region->region_id)
            ->where('signal_type_id', $this->dischargeId())
            ->get();

        $this->assertCount(6, $rows);
        $this->assertTrue($rows->every(fn ($r) => $r->raw_metadata['backfill'] === true));
        $this->assertTrue($rows->every(fn ($r) => str_contains($r->source, 'backfill')));
        // Each period sits entirely before the live ingestion window.
        [$liveWindowStart] = IngestionWindow::lastComplete();
        $this->assertTrue($rows->every(fn ($r) => $r->period_end->lt($liveWindowStart)));

        // Historical context only — must not retroactively trip alerts.
        Event::assertNotDispatched(RegionSignalIngested::class);
    }

    public function test_a_week_missing_most_of_its_days_is_left_out(): void
    {
        $region = $this->riverRegion();
        // Only the two most recent days of the whole range come back.
        Http::fake(['flood-api.open-meteo.com/*' => function ($request) {
            $end = Carbon::parse($request['end_date']);

            return Http::response(['daily' => [
                'time' => [$end->copy()->subDay()->toDateString(), $end->toDateString()],
                'river_discharge' => [10.0, 12.0],
            ]], 200);
        }]);

        $this->artisan('signals:backfill-discharge', ['--weeks' => 4, '--region' => $region->region_id])
            ->assertSuccessful();

        $this->assertSame(0, RegionSignal::query()->where('signal_type_id', $this->dischargeId())->count());
    }

    public function test_it_never_overwrites_a_real_reading(): void
    {
        $region = $this->riverRegion();
        [$liveWindowStart] = IngestionWindow::lastComplete();
        $realPeriodEnd = $liveWindowStart->copy()->subDay();
        $realPeriodStart = $realPeriodEnd->copy()->subDays(6);

        $region->signals()->create([
            'signal_type_id' => $this->dischargeId(),
            'period_start' => $realPeriodStart->toDateString(),
            'period_end' => $realPeriodEnd->toDateString(),
            'value' => 4242, 'source' => 'Open-Meteo Flood API (GloFAS)', 'ingested_at' => now(),
        ]);

        $this->fakeFullSeries();
        $this->artisan('signals:backfill-discharge', ['--weeks' => 3, '--region' => $region->region_id])->assertSuccessful();

        $kept = RegionSignal::query()
            ->where('region_id', $region->region_id)
            ->where('period_start', $realPeriodStart->toDateString())
            ->first();
        $this->assertEquals(4242, $kept->value);
        $this->assertStringNotContainsString('backfill', $kept->source);
    }

    public function test_a_reach_off_the_modelled_network_is_skipped(): void
    {
        $region = $this->riverRegion();
        Http::fake(['flood-api.open-meteo.com/*' => Http::response([
            'daily' => ['time' => ['2026-01-01'], 'river_discharge' => [null]],
        ], 200)]);

        $this->artisan('signals:backfill-discharge', ['--weeks' => 4, '--region' => $region->region_id])->assertSuccessful();

        $this->assertSame(0, RegionSignal::query()->where('signal_type_id', $this->dischargeId())->count());
    }
}
