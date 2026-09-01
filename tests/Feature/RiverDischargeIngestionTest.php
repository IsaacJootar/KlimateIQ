<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionSignal;
use App\Services\Ingestion\RiverDischargeIngestionService;
use App\Support\IngestionCadence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T4 — the observed river-discharge signal (Open-Meteo Flood API / GloFAS).
 * Builds the per-LGA discharge history the Riverine Flood Forecast index measures against.
 */
class RiverDischargeIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function riverRegion(): Region
    {
        $region = Region::query()->orderBy('region_id')->first();
        $region->update(['latitude' => 7.8, 'longitude' => 6.74]); // Lokoja, on the Niger–Benue confluence

        return $region->fresh();
    }

    public function test_it_means_the_daily_discharge_over_the_period(): void
    {
        Http::fake([
            'flood-api.open-meteo.com/*' => Http::response([
                'daily' => [
                    'time' => ['2026-08-17', '2026-08-18', '2026-08-19'],
                    'river_discharge' => [1000.0, 2000.0, 3000.0],
                ],
            ], 200),
        ]);

        $signal = app(RiverDischargeIngestionService::class)->ingestForRegion(
            $this->riverRegion(), Carbon::parse('2026-08-17'), Carbon::parse('2026-08-23'),
        );

        $this->assertNotNull($signal);
        $this->assertEquals(2000.0, $signal->value);
        $this->assertSame('RIVER_DISCHARGE', $signal->signalType->code);
        $this->assertStringContainsString('GloFAS', $signal->source);
    }

    public function test_an_unmodelled_reach_yields_null_not_zero(): void
    {
        Http::fake([
            'flood-api.open-meteo.com/*' => Http::response([
                'daily' => ['time' => ['2026-08-17'], 'river_discharge' => [null]],
            ], 200),
        ]);

        $signal = app(RiverDischargeIngestionService::class)->ingestForRegion(
            $this->riverRegion(), Carbon::parse('2026-08-17'), Carbon::parse('2026-08-23'),
        );

        $this->assertNull($signal);
        $this->assertSame(0, RegionSignal::query()->count());
    }

    public function test_a_failed_request_yields_null(): void
    {
        Http::fake(['flood-api.open-meteo.com/*' => Http::response([], 503)]);

        $this->assertNull(
            app(RiverDischargeIngestionService::class)->ingestForRegion(
                $this->riverRegion(), Carbon::parse('2026-08-17'), Carbon::parse('2026-08-23'),
            ),
        );
    }

    public function test_it_is_a_registered_daily_source(): void
    {
        $this->assertContains(RiverDischargeIngestionService::class, config('ingestion.sources'));
        $this->assertContains('RIVER_DISCHARGE', IngestionCadence::DAILY);
    }
}
