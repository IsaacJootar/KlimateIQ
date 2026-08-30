<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionSignal;
use App\Services\Ingestion\ActiveFireIngestionService;
use App\Support\IngestionCadence;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * NASA FIRMS active-fire ingestion — the weight-0 confirmation series behind Wildfire Risk
 * (BUILD_PLAN.md T3). No-op without a map key, like the Earthdata-backed vegetation service.
 */
class ActiveFireIngestionTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'latitude,longitude,bright_ti4,scan,track,acq_date,acq_time,satellite,instrument,confidence,version,bright_ti5,frp,daynight';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        config(['services.firms.map_key' => 'test-map-key']);
    }

    private function regionWithCoordinates(): Region
    {
        $region = Region::query()->first();
        $region->update(['latitude' => 12.0022, 'longitude' => 8.5920]);

        return $region->fresh();
    }

    public function test_it_counts_detections_in_the_bounding_box(): void
    {
        Http::fake([
            'firms.modaps.eosdis.nasa.gov/*' => Http::response(implode("\n", [
                self::HEADER,
                '11.94,8.58,327.5,0.47,0.4,2026-08-24,115,N20,VIIRS,n,2.0NRT,293.4,2.96,N',
                '11.89,8.47,347.9,0.48,0.48,2026-08-24,1223,N20,VIIRS,n,2.0NRT,296.8,9.02,D',
                '12.01,8.60,334.2,0.47,0.47,2026-08-25,1223,N20,VIIRS,n,2.0NRT,298.7,3.29,D',
            ]), 200),
        ]);

        $signal = app(ActiveFireIngestionService::class)->ingestForRegion(
            $this->regionWithCoordinates(), Carbon::parse('2026-08-19'), Carbon::parse('2026-08-25'),
        );

        $this->assertNotNull($signal);
        $this->assertEquals(3, $signal->value);
        $this->assertSame('ACTIVE_FIRE', $signal->signalType->code);
        $this->assertEqualsWithDelta(15.27, $signal->raw_metadata['total_frp_mw'], 0.01);
    }

    public function test_an_empty_result_is_zero_fires_not_missing_data(): void
    {
        Http::fake(['firms.modaps.eosdis.nasa.gov/*' => Http::response(self::HEADER, 200)]);

        $signal = app(ActiveFireIngestionService::class)->ingestForRegion(
            $this->regionWithCoordinates(), Carbon::parse('2026-08-19'), Carbon::parse('2026-08-25'),
        );

        $this->assertNotNull($signal);
        $this->assertEquals(0, $signal->value);
        $this->assertSame(1, RegionSignal::count());
    }

    public function test_it_is_a_noop_without_a_map_key(): void
    {
        config(['services.firms.map_key' => null]);
        Http::fake(); // any request would be a failure

        $signal = app(ActiveFireIngestionService::class)->ingestForRegion(
            $this->regionWithCoordinates(), Carbon::parse('2026-08-19'), Carbon::parse('2026-08-25'),
        );

        $this->assertNull($signal);
        $this->assertSame(0, RegionSignal::count());
        Http::assertNothingSent();
    }

    public function test_a_plain_text_error_body_throws_rather_than_being_stored_as_data(): void
    {
        Http::fake(['firms.modaps.eosdis.nasa.gov/*' => Http::response('Invalid MAP_KEY.', 200)]);

        $this->expectException(RuntimeException::class);

        app(ActiveFireIngestionService::class)->ingestForRegion(
            $this->regionWithCoordinates(), Carbon::parse('2026-08-19'), Carbon::parse('2026-08-25'),
        );
    }

    public function test_it_returns_null_when_the_request_fails(): void
    {
        Http::fake(['firms.modaps.eosdis.nasa.gov/*' => Http::response('', 500)]);

        $this->assertNull(app(ActiveFireIngestionService::class)->ingestForRegion(
            $this->regionWithCoordinates(), Carbon::parse('2026-08-19'), Carbon::parse('2026-08-25'),
        ));
    }

    public function test_it_is_a_registered_ingestion_source_on_the_daily_cadence(): void
    {
        $this->assertContains(ActiveFireIngestionService::class, config('ingestion.sources'));
        $this->assertContains('ACTIVE_FIRE', IngestionCadence::DAILY);
    }
}
