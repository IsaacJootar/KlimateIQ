<?php

namespace Tests\Feature;

use App\Models\IndexActionRecommendation;
use App\Models\Region;
use App\Models\RegionScoringConfig;
use App\Models\RegionSignal;
use App\Models\ScoringIndex;
use App\Models\Sector;
use App\Models\SignalType;
use App\Models\User;
use App\Services\Scoring\RegionScoringService;
use App\Support\IndexCoverage;
use App\Support\IngestionCadence;
use App\Support\IngestionWindow;
use Database\Seeders\AdditionalIndicesSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BUILD_PLAN.md T3 — Wildfire Risk and Dust Storm Risk, on three new free Open-Meteo signals
 * (HUMIDITY, WIND_SPEED, DUST). NASA FIRMS active-fire confirmation is a later add (needs a
 * map key); the indices score on the weather signals today.
 */
class FireAndDustIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    private function index(string $code): ScoringIndex
    {
        return ScoringIndex::query()->where('code', $code)->firstOrFail();
    }

    private function signal(Region $region, string $code, float $value, Carbon $start, Carbon $end): void
    {
        RegionSignal::query()->create([
            'region_id' => $region->region_id,
            'signal_type_id' => SignalType::query()->where('code', $code)->value('signal_type_id'),
            'period_start' => $start,
            'period_end' => $end,
            'value' => $value,
            'source' => 'test',
            'ingested_at' => now(),
        ]);
    }

    public function test_the_new_signal_types_are_seeded_with_the_right_default_direction(): void
    {
        $this->assertFalse((bool) SignalType::query()->where('code', 'HUMIDITY')->value('higher_is_worse'));
        $this->assertTrue((bool) SignalType::query()->where('code', 'WIND_SPEED')->value('higher_is_worse'));
        $this->assertTrue((bool) SignalType::query()->where('code', 'DUST')->value('higher_is_worse'));

        foreach (['HUMIDITY', 'WIND_SPEED', 'DUST'] as $code) {
            $this->assertContains($code, IngestionCadence::DAILY);
        }
    }

    public function test_wildfire_risk_is_seeded_and_attached_to_emergency_response(): void
    {
        $this->assertSame('Wildfire Risk Index', $this->index('WILDFIRE_RISK')->name);
        $this->assertEqualsCanonicalizing(
            ['EMERGENCY_RESPONSE'],
            $this->index('WILDFIRE_RISK')->sectors->pluck('code')->all(),
        );

        $configs = RegionScoringConfig::query()
            ->where('index_id', $this->index('WILDFIRE_RISK')->index_id)
            ->whereNull('region_id')
            ->with('signalType')
            ->get()
            ->keyBy('signalType.code');

        $this->assertEqualsCanonicalizing(
            ['HUMIDITY', 'VEGETATION', 'WIND_SPEED', 'TEMPERATURE', 'ACTIVE_FIRE'],
            $configs->keys()->all(),
        );
        $this->assertEquals(0.3, $configs['HUMIDITY']->weight);
        $this->assertFalse((bool) $configs['VEGETATION']->higher_is_worse);
        // Fire detections ride along as confirmation only.
        $this->assertEquals(0.0, $configs['ACTIVE_FIRE']->weight);
    }

    public function test_dust_storm_risk_is_seeded_and_attached_to_two_sectors(): void
    {
        $this->assertSame('Dust Storm Risk Index', $this->index('DUST_STORM_RISK')->name);
        $this->assertEqualsCanonicalizing(
            ['EMERGENCY_RESPONSE', 'AIR_ENVIRONMENT'],
            $this->index('DUST_STORM_RISK')->sectors->pluck('code')->all(),
        );
    }

    public function test_each_index_has_an_action_for_every_risk_band(): void
    {
        foreach (['WILDFIRE_RISK', 'DUST_STORM_RISK'] as $code) {
            $this->assertEqualsCanonicalizing(
                ['green', 'amber', 'red'],
                IndexActionRecommendation::query()->where('index_id', $this->index($code)->index_id)->pluck('risk_band')->all(),
                "{$code} is missing a risk band",
            );
        }
    }

    public function test_an_air_environment_follower_sees_dust_storm_risk(): void
    {
        $user = User::factory()->create();
        $user->sectorSubscriptions()->create([
            'sector_id' => Sector::query()->where('code', 'AIR_ENVIRONMENT')->value('sector_id'),
        ]);

        $codes = IndexCoverage::resolve($user->fresh(), null)['available']->pluck('code');

        $this->assertTrue($codes->contains('DUST_STORM_RISK'));
        $this->assertTrue($codes->contains('RESPIRATORY_RISK'));
    }

    public function test_wildfire_risk_blends_the_four_weather_signals(): void
    {
        $region = Region::query()->firstOrFail();
        [$start, $end] = IngestionWindow::lastComplete();

        // HUMIDITY 20 -> inv 80 ; VEGETATION 0.0 in [-1,1] -> inv 50 ; WIND 20/40 -> 50 ; TEMP 39 in [15,45] -> 80
        // .3*80 + .3*50 + .2*50 + .2*80 = 65
        $this->signal($region, 'HUMIDITY', 20, $start, $end);
        $this->signal($region, 'VEGETATION', 0.0, $start, $end);
        $this->signal($region, 'WIND_SPEED', 20, $start, $end);
        $this->signal($region, 'TEMPERATURE', 39, $start, $end);
        // A pile of fire detections must not move the score — weight 0.
        $this->signal($region, 'ACTIVE_FIRE', 40, $start, $end);

        $result = app(RegionScoringService::class)->calculate($this->index('WILDFIRE_RISK'), $region, $start, $end);

        $this->assertSame(65.0, $result->score);

        $fireRow = collect($result->breakdown)->firstWhere('signal_type_code', 'ACTIVE_FIRE');
        $this->assertSame(0.0, $fireRow['contribution_to_final_score']);
    }

    public function test_dust_storm_risk_leads_with_measured_dust(): void
    {
        $region = Region::query()->firstOrFail();
        [$start, $end] = IngestionWindow::lastComplete();

        // DUST 250/500 -> 50 ; WIND 30/40 -> 75 ; HUMIDITY 10 -> inv 90
        // .6*50 + .3*75 + .1*90 = 61.5
        $this->signal($region, 'DUST', 250, $start, $end);
        $this->signal($region, 'WIND_SPEED', 30, $start, $end);
        $this->signal($region, 'HUMIDITY', 10, $start, $end);

        $result = app(RegionScoringService::class)->calculate($this->index('DUST_STORM_RISK'), $region, $start, $end);

        $this->assertSame(61.5, $result->score);
    }

    public function test_the_seeder_is_idempotent_and_never_overwrites_a_tuned_weight(): void
    {
        RegionScoringConfig::query()
            ->where('index_id', $this->index('DUST_STORM_RISK')->index_id)
            ->whereNull('region_id')
            ->whereHas('signalType', fn ($q) => $q->where('code', 'DUST'))
            ->update(['weight' => 0.75]);

        $this->seed(AdditionalIndicesSeeder::class);

        $this->assertSame(1, ScoringIndex::query()->where('code', 'WILDFIRE_RISK')->count());
        $this->assertEquals(
            0.75,
            RegionScoringConfig::query()
                ->where('index_id', $this->index('DUST_STORM_RISK')->index_id)
                ->whereNull('region_id')
                ->whereHas('signalType', fn ($q) => $q->where('code', 'DUST'))
                ->value('weight'),
        );
    }
}
