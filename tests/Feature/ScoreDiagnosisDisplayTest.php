<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The rebuilt region page (Clarity Pass C) reads top to bottom as one story: this week's
 * readings, the score, what's pushing it, what it means, where it's heading, what to do. This
 * covers the deterministic narrative — no AI summary needed — and that step 6 names the same
 * driver as step 3.
 */
class ScoreDiagnosisDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_the_page_walks_the_score_from_readings_to_action(): void
    {
        $user = User::factory()->create();
        $region = Region::query()->first();
        $index = ScoringIndex::where('code', 'MALARIA_RISK')->firstOrFail();

        RegionScore::create([
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'period_start' => Carbon::parse('2026-08-03'),
            'period_end' => Carbon::parse('2026-08-09'),
            'score' => 72.0,
            'scoring_strategy' => 'formula',
            'breakdown' => [
                ['signal_type_code' => 'RAINFALL', 'signal_type_name' => 'Rainfall', 'raw_value' => 95, 'unit' => 'mm', 'normalized_score' => 20, 'weight' => 0.3, 'contribution_to_final_score' => 12.0],
                ['signal_type_code' => 'STANDING_WATER', 'signal_type_name' => 'Standing Water', 'raw_value' => 90, 'unit' => '%', 'normalized_score' => 90, 'weight' => 0.7, 'contribution_to_final_score' => 60.0],
            ],
            'calculated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'MALARIA_RISK']));

        $response->assertOk();
        // Step 1 — readings, phrased plainly, not raw codes.
        $response->assertSee('This week in '.$region->name, false);
        $response->assertSee('of rain', false);
        $response->assertDontSee('STANDING_WATER');
        // Step 3 — named drivers.
        $response->assertSee("What's pushing it up");
        $response->assertSee('Standing Water', false);
        // Step 4 — plain-English meaning.
        $response->assertSee('High risk this week', false);
        // Step 6 — action, tied back to the driver.
        $response->assertSee('What to do', false);
        $response->assertSee('Distribute RDTs and pre-position ACTs', false);
        $response->assertSee('Driven mainly by Standing Water', false);
    }

    public function test_a_low_score_frames_step_three_as_what_is_keeping_it_low(): void
    {
        $user = User::factory()->create();
        $region = Region::query()->first();
        $index = ScoringIndex::where('code', 'MALARIA_RISK')->firstOrFail();

        RegionScore::create([
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'period_start' => Carbon::parse('2026-08-03'),
            'period_end' => Carbon::parse('2026-08-09'),
            'score' => 18.0,
            'scoring_strategy' => 'formula',
            'breakdown' => [
                ['signal_type_code' => 'RAINFALL', 'signal_type_name' => 'Rainfall', 'raw_value' => 12, 'unit' => 'mm', 'normalized_score' => 20, 'weight' => 0.5, 'contribution_to_final_score' => 10.0],
                ['signal_type_code' => 'STANDING_WATER', 'signal_type_name' => 'Standing Water', 'raw_value' => 8, 'unit' => '%', 'normalized_score' => 8, 'weight' => 0.5, 'contribution_to_final_score' => 8.0],
            ],
            'calculated_at' => now(),
        ]);

        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'MALARIA_RISK']))
            ->assertOk()
            ->assertSee("What's keeping it low")
            ->assertSee('Low risk this week', false);
    }

    public function test_no_score_yet_shows_what_it_is_waiting_on(): void
    {
        $user = User::factory()->create();
        $region = Region::query()->first();
        $index = ScoringIndex::where('code', 'HEAT_STRESS_RISK')->firstOrFail();

        RegionScore::create([
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'period_start' => Carbon::parse('2026-08-03'),
            'period_end' => Carbon::parse('2026-08-09'),
            'score' => null,
            'scoring_strategy' => 'formula',
            'breakdown' => [
                ['signal_type_code' => 'TEMPERATURE', 'signal_type_name' => 'Temperature', 'status' => 'no_data', 'weight' => 0.7],
                ['signal_type_code' => 'VEGETATION', 'signal_type_name' => 'Vegetation Cover', 'status' => 'no_data', 'weight' => 0.3],
            ],
            'calculated_at' => now(),
        ]);

        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'HEAT_STRESS_RISK']))
            ->assertOk()
            ->assertSee('No score yet', false)
            ->assertSee('Waiting on', false)
            ->assertSee("pending this week's update", false);
    }

    public function test_a_region_with_no_score_row_at_all_still_renders(): void
    {
        $user = User::factory()->create();
        $region = Region::query()->first();

        $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'MALARIA_RISK']))
            ->assertOk()
            ->assertSee('No score yet', false);
    }
}
