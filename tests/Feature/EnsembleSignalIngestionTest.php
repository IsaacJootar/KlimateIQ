<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionForecastSignal;
use App\Models\RegionSignal;
use App\Models\SignalType;
use App\Services\Ingestion\RainfallEnsembleService;
use App\Services\Ingestion\RiverDischargeEnsembleService;
use App\Services\Ingestion\RiverDischargeForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T5 — the ensemble signal lane. Member series land in region_forecast_signals
 * tagged by member id, alongside (never replacing) the deterministic 'control' series.
 */
class EnsembleSignalIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function riverRegion(): Region
    {
        $region = Region::query()->orderBy('region_id')->first();
        $region->update(['latitude' => 7.8, 'longitude' => 6.74]);

        return $region->fresh();
    }

    /** Flood API with &ensemble=true — N members, each a ramp offset by its member number. */
    private function fakeFloodEnsemble(int $members = 12): void
    {
        Http::fake(['flood-api.open-meteo.com/*' => function ($request) use ($members) {
            $start = Carbon::parse($request['start_date']);
            $end = Carbon::parse($request['end_date']);
            $time = [];
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $time[] = $d->toDateString();
            }
            $daily = ['time' => $time, 'river_discharge' => array_map(fn ($i) => 1000.0 + $i * 50, array_keys($time))];
            for ($m = 1; $m <= $members; $m++) {
                $daily['river_discharge_member'.str_pad((string) $m, 2, '0', STR_PAD_LEFT)]
                    = array_map(fn ($i) => 900.0 + $i * 50 + $m * 30, array_keys($time));
            }

            return Http::response(['daily' => $daily], 200);
        }]);
    }

    public function test_it_writes_member_rows_tagged_by_member_id(): void
    {
        $this->fakeFloodEnsemble(10);
        $region = $this->riverRegion();

        $written = app(RiverDischargeEnsembleService::class)
            ->ingestEnsembleForRegion($region, Carbon::parse('2026-09-01'), 3);

        $this->assertSame(40, $written); // 10 members × 4 days
        $this->assertSame(0, RegionSignal::query()->count(), 'ensemble data must not touch the observed table');

        $rows = RegionForecastSignal::query()->orderBy('member')->orderBy('target_date')->get();
        $this->assertSame(40, $rows->count());
        $this->assertEqualsCanonicalizing(
            ['glofas-01', 'glofas-02', 'glofas-03', 'glofas-04', 'glofas-05', 'glofas-06', 'glofas-07', 'glofas-08', 'glofas-09', 'glofas-10'],
            $rows->pluck('member')->unique()->values()->all(),
        );
        $first = $rows->firstWhere('member', 'glofas-01');
        $this->assertSame(0, $first->lead_days);
        $this->assertSame('2026-09-01', $first->forecast_issued_at->toDateString());
    }

    public function test_the_control_series_is_left_untouched_by_an_ensemble_run(): void
    {
        $this->fakeFloodEnsemble();
        $region = $this->riverRegion();

        app(RiverDischargeForecastService::class)->ingestForecastForRegion($region, Carbon::parse('2026-09-01'), 3);
        $controlBefore = RegionForecastSignal::query()->where('member', 'control')->count();
        $this->assertSame(4, $controlBefore);

        app(RiverDischargeEnsembleService::class)->ingestEnsembleForRegion($region, Carbon::parse('2026-09-01'), 3);

        $this->assertSame(4, RegionForecastSignal::query()->where('member', 'control')->count());
        $this->assertGreaterThan(0, RegionForecastSignal::query()->where('member', '!=', 'control')->count());
    }

    public function test_a_control_run_does_not_wipe_ensemble_members(): void
    {
        $this->fakeFloodEnsemble();
        $region = $this->riverRegion();

        app(RiverDischargeEnsembleService::class)->ingestEnsembleForRegion($region, Carbon::parse('2026-09-01'), 3);
        $membersBefore = RegionForecastSignal::query()->where('member', '!=', 'control')->count();
        $this->assertGreaterThan(0, $membersBefore);

        app(RiverDischargeForecastService::class)->ingestForecastForRegion($region, Carbon::parse('2026-09-01'), 3);

        $this->assertSame($membersBefore, RegionForecastSignal::query()->where('member', '!=', 'control')->count());
    }

    public function test_a_second_ensemble_run_replaces_only_the_member_rows(): void
    {
        $this->fakeFloodEnsemble(8);
        $region = $this->riverRegion();

        app(RiverDischargeEnsembleService::class)->ingestEnsembleForRegion($region, Carbon::parse('2026-09-01'), 3);
        app(RiverDischargeEnsembleService::class)->ingestEnsembleForRegion($region, Carbon::parse('2026-09-02'), 3);

        $rows = RegionForecastSignal::query()->where('member', '!=', 'control')->get();
        $this->assertTrue($rows->every(fn ($r) => $r->forecast_issued_at->toDateString() === '2026-09-02'));
        $this->assertTrue($rows->every(fn ($r) => $r->target_date->toDateString() >= '2026-09-02'));
    }

    public function test_an_unmodelled_reach_writes_nothing_and_does_not_throw(): void
    {
        Http::fake(['flood-api.open-meteo.com/*' => Http::response(['daily' => ['time' => ['2026-09-01'], 'river_discharge' => [null]]], 200)]);
        $region = $this->riverRegion();

        $written = app(RiverDischargeEnsembleService::class)
            ->ingestEnsembleForRegion($region, Carbon::parse('2026-09-01'), 3);

        $this->assertSame(0, $written);
        $this->assertSame(0, RegionForecastSignal::query()->count());
    }

    public function test_the_weather_ensemble_pools_members_across_models(): void
    {
        $region = $this->riverRegion();
        Http::fake(['ensemble-api.open-meteo.com/*' => function ($request) {
            $model = $request['models'];
            $start = Carbon::parse($request['start_date']);
            $end = Carbon::parse($request['end_date']);
            $time = [];
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $time[] = $d->toDateString();
            }
            $count = $model === 'ecmwf_ifs04' ? 3 : 2;
            $daily = ['time' => $time, 'precipitation_sum' => array_fill(0, count($time), 1.0)];
            for ($m = 1; $m <= $count; $m++) {
                $daily['precipitation_sum_member'.str_pad((string) $m, 2, '0', STR_PAD_LEFT)] = array_fill(0, count($time), (float) $m);
            }

            return Http::response(['daily' => $daily], 200);
        }]);

        $written = app(RainfallEnsembleService::class)
            ->ingestEnsembleForRegion($region, Carbon::parse('2026-09-01'), 2);

        $members = RegionForecastSignal::query()
            ->where('signal_type_id', SignalType::query()->where('code', 'RAINFALL')->value('signal_type_id'))
            ->pluck('member')->unique()->values()->all();

        // 2 gfs + 3 ecmwf + 2 icon
        $this->assertCount(7, $members);
        $this->assertContains('gfs-01', $members);
        $this->assertContains('ecmwf-03', $members);
        $this->assertContains('icon-02', $members);
        $this->assertSame(7 * 3, $written);
    }

    public function test_the_command_ingests_members_for_a_region(): void
    {
        $this->fakeFloodEnsemble(6);
        $region = $this->riverRegion();

        $this->artisan('signals:ingest-ensemble', ['--sync' => true, '--region' => $region->region_id, '--horizon' => 1, '--source' => 'RIVER_DISCHARGE'])
            ->assertSuccessful();

        $dischargeId = SignalType::query()->where('code', 'RIVER_DISCHARGE')->value('signal_type_id');
        $this->assertSame(12, RegionForecastSignal::query()->where('signal_type_id', $dischargeId)->where('member', '!=', 'control')->count());
    }

    public function test_the_ensemble_sources_are_registered(): void
    {
        $this->assertContains(RiverDischargeEnsembleService::class, config('ingestion.ensemble_sources'));
        $this->assertContains(RainfallEnsembleService::class, config('ingestion.ensemble_sources'));
    }
}
