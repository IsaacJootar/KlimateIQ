<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionForecastSignal;
use App\Models\RegionSignal;
use App\Models\SignalType;
use App\Services\Ingestion\RiverDischargeForecastService;
use Database\Seeders\AdditionalIndicesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T4 — the forecast signal lane. Forward series lands in region_forecast_signals,
 * never region_signals, with the right issue date / target date / lead time.
 */
class ForecastSignalIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function riverRegion(): Region
    {
        // orderBy — a bare first() isn't stable across the UPDATE below in Postgres (MVCC moves
        // the tuple), and this helper is called more than once per test.
        $region = Region::query()->orderBy('region_id')->first();
        $region->update(['latitude' => 7.8, 'longitude' => 6.74]);

        return $region->fresh();
    }

    /**
     * Fake the flood API so every request is answered from the issue date (its start_date query
     * param) forward — mirrors how GloFAS actually serves a forecast, and lets one fake cover
     * multiple issuances in a test.
     */
    private function fakeFlood(float $base = 1000.0, float $step = 100.0): void
    {
        Http::fake(['flood-api.open-meteo.com/*' => function ($request) use ($base, $step) {
            $start = Carbon::parse($request['start_date']);
            $end = Carbon::parse($request['end_date']);
            $time = [];
            $discharge = [];
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $time[] = $d->toDateString();
                $discharge[] = $base + (count($time) - 1) * $step;
            }

            return Http::response(['daily' => ['time' => $time, 'river_discharge' => $discharge]], 200);
        }]);
    }

    public function test_it_writes_one_forward_row_per_day_with_lead_times(): void
    {
        $this->fakeFlood();

        $rows = app(RiverDischargeForecastService::class)->ingestForecastForRegion(
            $this->riverRegion(), Carbon::parse('2026-09-01'), 3,
        );

        $this->assertCount(4, $rows); // day 0..3
        $this->assertSame(0, RegionSignal::query()->count(), 'forecast data must not touch the observed table');

        $stored = RegionForecastSignal::query()->orderBy('target_date')->get();
        $this->assertSame(
            ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'],
            $stored->pluck('target_date')->map->toDateString()->all(),
        );
        $this->assertSame([0, 1, 2, 3], $stored->pluck('lead_days')->all());
        $this->assertSame('2026-09-01', $stored->first()->forecast_issued_at->toDateString());
        $this->assertEquals(1300.0, $stored->last()->value);
    }

    public function test_a_later_issuance_replaces_the_earlier_forecast_and_prunes_stale_days(): void
    {
        $this->fakeFlood();
        app(RiverDischargeForecastService::class)->ingestForecastForRegion($this->riverRegion(), Carbon::parse('2026-09-01'), 2);
        $this->assertSame(3, RegionForecastSignal::query()->count()); // 09-01..09-03

        // The next day's run.
        app(RiverDischargeForecastService::class)->ingestForecastForRegion($this->riverRegion(), Carbon::parse('2026-09-02'), 2);

        $all = RegionForecastSignal::query()->orderBy('target_date')->get();
        // 09-01 pruned (now in the past); 09-02..09-04 from the new issuance, re-based on it.
        $this->assertSame(['2026-09-02', '2026-09-03', '2026-09-04'], $all->pluck('target_date')->map->toDateString()->all());
        $this->assertTrue($all->every(fn ($r) => $r->forecast_issued_at->toDateString() === '2026-09-02'));
        $this->assertSame([0, 1, 2], $all->pluck('lead_days')->all());
        $this->assertEquals(1100.0, $all[1]->value); // 09-03 is now +1 day from the issue date: base 1000 + one step
    }

    public function test_an_unmodelled_reach_yields_an_empty_collection(): void
    {
        Http::fake(['flood-api.open-meteo.com/*' => Http::response([
            'daily' => ['time' => ['2026-09-01'], 'river_discharge' => [null]],
        ], 200)]);

        $rows = app(RiverDischargeForecastService::class)->ingestForecastForRegion(
            $this->riverRegion(), Carbon::parse('2026-09-01'), 3,
        );

        $this->assertTrue($rows->isEmpty());
        $this->assertSame(0, RegionForecastSignal::query()->count());
    }

    public function test_the_command_ingests_for_active_regions(): void
    {
        $this->fakeFlood();

        $region = $this->riverRegion();
        $region->signals()->create([
            'signal_type_id' => SignalType::query()->where('code', 'RAINFALL')->value('signal_type_id'),
            'period_start' => '2026-08-17', 'period_end' => '2026-08-23', 'value' => 10, 'source' => 'test', 'ingested_at' => now(),
        ]);

        $this->artisan('signals:ingest-forecast', ['--sync' => true, '--region' => $region->region_id, '--horizon' => 1])
            ->assertSuccessful();

        $this->assertSame(2, RegionForecastSignal::query()->count());
    }

    public function test_the_forecast_source_is_registered_and_the_signal_type_is_seeded(): void
    {
        $this->assertContains(RiverDischargeForecastService::class, config('ingestion.forecast_sources'));

        $this->seed(AdditionalIndicesSeeder::class); // idempotent

        $this->assertSame(
            'River Discharge',
            SignalType::query()->where('code', 'RIVER_DISCHARGE')->value('name'),
        );
    }
}
