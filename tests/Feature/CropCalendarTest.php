<?php

namespace Tests\Feature;

use App\Models\CropCalendar;
use App\Models\Region;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use App\Models\User;
use App\Support\AgroZone;
use Database\Seeders\CropCalendarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Clarity Pass D2 — the crop calendar: which crops are in a water-sensitive growth stage,
 * where, and when. Reference data + a lookup; the visible payoff is on the agriculture region
 * pages (a "crops most exposed" line in the recommendation).
 */
class CropCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_covers_every_agro_ecological_zone(): void
    {
        $seededZones = CropCalendar::query()->where('scope', 'zone')->distinct()->pluck('scope_key')->sort()->values();

        $this->assertEqualsCanonicalizing(AgroZone::zones(), $seededZones->all());
        $this->assertGreaterThan(20, CropCalendar::query()->count());
    }

    public function test_states_map_to_the_right_zone(): void
    {
        $this->assertSame(AgroZone::SUDAN_SAVANNA, AgroZone::forState('Kano'));
        $this->assertSame(AgroZone::HUMID_FOREST, AgroZone::forState('Lagos'));
        $this->assertSame(AgroZone::NORTHERN_GUINEA, AgroZone::forState('FCT'));
        $this->assertSame(AgroZone::SUDAN_SAVANNA, AgroZone::forState('  kano '));   // trimmed, case-insensitive
        $this->assertNull(AgroZone::forState('Atlantis'));
        $this->assertNull(AgroZone::forState(null));
    }

    public function test_exposed_now_returns_crops_in_a_sensitive_window_for_the_state(): void
    {
        // Late August in the Sudan Savanna — millet and maize are at grain-fill.
        $august = Carbon::parse('2026-08-20');
        $crops = CropCalendar::exposedNow('Kano', $august);

        $this->assertEqualsCanonicalizing(
            ['Millet', 'Maize'],
            $crops->pluck('crop')->all(),
        );
        $this->assertSame('filling grain', $crops->firstWhere('crop', 'Millet')['stage']);
    }

    public function test_exposed_now_is_empty_for_a_state_with_no_zone(): void
    {
        $this->assertTrue(CropCalendar::exposedNow('Atlantis', Carbon::parse('2026-08-20'))->isEmpty());
    }

    public function test_a_state_scoped_row_overrides_the_zone_row_for_the_same_crop(): void
    {
        CropCalendar::query()->create([
            'scope' => 'state', 'scope_key' => 'Kano', 'crop' => 'Millet',
            'stage' => 'local heading', 'sensitive_months' => [8], 'sort_order' => 0,
        ]);

        $millet = CropCalendar::exposedNow('Kano', Carbon::parse('2026-08-20'))->firstWhere('crop', 'Millet');

        $this->assertSame('local heading', $millet['stage']);
    }

    public function test_phrase_for_groups_crops_by_stage(): void
    {
        // Northern Guinea Savanna in September — several crops across a few stages.
        $phrase = CropCalendar::phraseFor('Kaduna', Carbon::parse('2026-09-15'));

        $this->assertNotNull($phrase);
        $this->assertStringContainsString('(filling grain)', $phrase);
        $this->assertStringContainsString('(flowering)', $phrase);   // more than one stage
        $this->assertStringNotContainsString('Maize', $phrase);      // lower-cased
        $this->assertStringNotContainsString('near', $phrase);
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $before = CropCalendar::query()->count();
        $this->seed(CropCalendarSeeder::class);

        $this->assertSame($before, CropCalendar::query()->count());
    }

    public function test_the_agriculture_region_page_names_exposed_crops_when_the_score_is_high(): void
    {
        Carbon::setTestNow('2026-09-15'); // Kano cereals at grain-fill

        $user = User::factory()->create();
        $region = Region::query()->where('state', 'Kano')->firstOrFail();
        $index = ScoringIndex::query()->where('code', 'AGRICULTURE_STRESS')->firstOrFail();

        RegionScore::query()->create([
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'period_start' => Carbon::parse('2026-09-06'),
            'period_end' => Carbon::parse('2026-09-12'),
            'score' => 68.0,
            'scoring_strategy' => 'formula',
            'breakdown' => [
                ['signal_type_code' => 'SOIL_MOISTURE', 'signal_type_name' => 'Soil Moisture (Root Zone)', 'raw_value' => 0.1, 'unit' => 'm³/m³', 'normalized_score' => 80, 'weight' => 0.5, 'contribution_to_final_score' => 40.0],
            ],
            'calculated_at' => now(),
        ]);

        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'AGRICULTURE_STRESS']))
            ->assertOk()
            ->assertSee('typically include', false)
            ->assertSee('(filling grain)', false)
            ->assertSee('not a full field survey', false);
    }

    public function test_a_low_score_and_a_non_agriculture_index_show_no_crop_line(): void
    {
        Carbon::setTestNow('2026-09-15');

        $user = User::factory()->create();
        $region = Region::query()->where('state', 'Kano')->firstOrFail();

        // Low agriculture score — nothing to act on.
        $agri = ScoringIndex::query()->where('code', 'DROUGHT_RISK')->firstOrFail();
        RegionScore::query()->create([
            'region_id' => $region->region_id, 'index_id' => $agri->index_id,
            'period_start' => Carbon::parse('2026-08-10'), 'period_end' => Carbon::parse('2026-08-16'),
            'score' => 12.0, 'scoring_strategy' => 'formula',
            'breakdown' => [['signal_type_code' => 'RAINFALL', 'signal_type_name' => 'Rainfall', 'raw_value' => 90, 'unit' => 'mm', 'normalized_score' => 20, 'weight' => 1.0, 'contribution_to_final_score' => 12.0]],
            'calculated_at' => now(),
        ]);

        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'DROUGHT_RISK']))
            ->assertOk()
            ->assertDontSee('typically include');

        // High non-agriculture score — crops aren't relevant.
        $flood = ScoringIndex::query()->where('code', 'FLOOD_RISK')->firstOrFail();
        RegionScore::query()->create([
            'region_id' => $region->region_id, 'index_id' => $flood->index_id,
            'period_start' => Carbon::parse('2026-08-10'), 'period_end' => Carbon::parse('2026-08-16'),
            'score' => 80.0, 'scoring_strategy' => 'formula',
            'breakdown' => [['signal_type_code' => 'RAINFALL', 'signal_type_name' => 'Rainfall', 'raw_value' => 190, 'unit' => 'mm', 'normalized_score' => 95, 'weight' => 1.0, 'contribution_to_final_score' => 80.0]],
            'calculated_at' => now(),
        ]);

        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'FLOOD_RISK']))
            ->assertOk()
            ->assertDontSee('typically include');
    }
}
