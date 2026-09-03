<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionForecastScore;
use App\Models\RegionForecastSignal;
use App\Models\RegionScoringConfig;
use App\Models\RiverReach;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Models\User;
use App\Services\Scoring\RegionForecastScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * T4/T5 follow-up — a confluence LGA is scored per named river reach. The headline is the worst
 * reach; the page names it. A single-reach LGA is byte-identical to before.
 */
class ReachLevelForecastTest extends TestCase
{
    use RefreshDatabase;

    private ScoringIndex $index;

    private Region $region;

    private int $dischargeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dischargeId = SignalType::query()->where('code', 'RIVER_DISCHARGE')->value('signal_type_id');
        $this->index = ScoringIndex::query()->where('code', 'RIVERINE_FLOOD_FORECAST')->firstOrFail();
        $this->region = Region::query()->orderBy('region_id')->first();
    }

    private function reach(string $slug, string $river): void
    {
        RiverReach::query()->create([
            'region_id' => $this->region->region_id, 'reach' => $slug, 'river' => $river,
            'latitude' => 7.8, 'longitude' => 6.75, 'source' => 'test',
        ]);
    }

    private function bound(string $reach, float $max): void
    {
        foreach (['MIN' => 0, 'MAX' => $max] as $suffix => $value) {
            ScoringCalibrationParameter::query()->create([
                'index_id' => $this->index->index_id, 'region_id' => $this->region->region_id, 'reach' => $reach,
                'parameter_key' => "RIVER_DISCHARGE_{$suffix}", 'parameter_value' => $value, 'calibration_status' => 'reference_derived',
            ]);
        }
    }

    private function forecastDay(string $reach, float $value, int $lead = 2): void
    {
        RegionForecastSignal::query()->create([
            'region_id' => $this->region->region_id, 'signal_type_id' => $this->dischargeId,
            'member' => 'control', 'reach' => $reach,
            'forecast_issued_at' => '2026-09-01', 'target_date' => Carbon::parse('2026-09-01')->addDays($lead)->toDateString(),
            'lead_days' => $lead, 'value' => $value, 'source' => 'test', 'ingested_at' => now(),
        ]);
    }

    public function test_the_worst_reach_drives_the_headline_and_all_reaches_are_listed(): void
    {
        RegionScoringConfig::query()->where('index_id', $this->index->index_id)->update(['region_id' => null]);
        $this->reach('niger-x', 'Niger');
        $this->reach('benue-x', 'Benue');
        $this->bound('niger-x', 20000);   // Niger normal → low score
        $this->bound('benue-x', 8000);    // Benue at its 20-yr level → ~100

        $this->forecastDay('niger-x', 6000);   // 6000/20000 → ~30
        $this->forecastDay('benue-x', 8000);   // 8000/8000 → 100

        $result = app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'), withEnsemble: false);

        $this->assertEqualsWithDelta(100.0, $result->score, 0.5);
        $this->assertSame('benue-x', $result->breakdown['driving_reach']);
        $this->assertSame('Benue', $result->breakdown['driving_river']);

        $reaches = collect($result->breakdown['reaches']);
        $this->assertEqualsCanonicalizing(['Niger', 'Benue'], $reaches->pluck('river')->all());
        $this->assertLessThan(40, $reaches->firstWhere('river', 'Niger')['score']);
    }

    public function test_an_uncalibrated_reach_is_dropped_not_pegged(): void
    {
        RegionScoringConfig::query()->where('index_id', $this->index->index_id)->update(['region_id' => null]);
        $this->reach('niger-x', 'Niger');
        $this->reach('benue-x', 'Benue');
        $this->bound('niger-x', 20000);          // only the Niger is calibrated
        $this->forecastDay('niger-x', 6000);
        $this->forecastDay('benue-x', 9999);     // would peg at 100 against a borrowed bound

        $result = app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'), withEnsemble: false);

        $this->assertLessThan(40, $result->score);                       // the Niger, not the pegged Benue
        $this->assertSame('niger-x', $result->breakdown['driving_reach']);
        $this->assertContains('Benue', $result->breakdown['uncalibrated_reaches']);
    }

    public function test_every_reach_uncalibrated_is_calibration_pending(): void
    {
        RegionScoringConfig::query()->where('index_id', $this->index->index_id)->update(['region_id' => null]);
        $this->reach('niger-x', 'Niger');
        $this->forecastDay('niger-x', 6000);

        $result = app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'), withEnsemble: false);

        $this->assertNull($result->score);
        $this->assertSame('calibration_pending', $result->breakdown['status']);
    }

    public function test_a_single_reach_lga_keeps_the_centroid_behaviour(): void
    {
        RegionScoringConfig::query()->where('index_id', $this->index->index_id)->update(['region_id' => null]);
        // no river_reaches rows → 'centroid'
        foreach (['MIN' => 0, 'MAX' => 1000] as $s => $v) {
            ScoringCalibrationParameter::query()->create([
                'index_id' => $this->index->index_id, 'region_id' => $this->region->region_id, 'reach' => null,
                'parameter_key' => "RIVER_DISCHARGE_{$s}", 'parameter_value' => $v, 'calibration_status' => 'reference_derived',
            ]);
        }
        $this->forecastDay('centroid', 800);

        $result = app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse('2026-09-01'), withEnsemble: false);

        $this->assertEqualsWithDelta(80.0, $result->score, 0.5);
        $this->assertSame('centroid', $result->breakdown['driving_reach']);
        $this->assertNull($result->breakdown['driving_river']);
        $this->assertCount(1, $result->breakdown['reaches']);
    }

    public function test_the_region_page_shows_the_by_river_panel(): void
    {
        RegionScoringConfig::query()->where('index_id', $this->index->index_id)->update(['region_id' => null]);
        $this->reach('niger-x', 'Niger');
        $this->reach('benue-x', 'Benue');
        $this->bound('niger-x', 20000);
        $this->bound('benue-x', 8000);
        foreach ([0, 1, 2] as $lead) {
            $this->forecastDay('niger-x', 6000, $lead);
            $this->forecastDay('benue-x', 7600, $lead);
        }

        app(RegionForecastScoringService::class)->calculate($this->index, $this->region, Carbon::parse(now()->toDateString()), withEnsemble: false);

        $this->actingAs(User::factory()->create())
            ->get(route('regions.show', ['region' => $this->region, 'index' => 'RIVERINE_FLOOD_FORECAST']))
            ->assertOk()
            ->assertSee('By river')
            ->assertSee('is driving this', false);
    }
}
