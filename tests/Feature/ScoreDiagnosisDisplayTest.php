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
 * Regression: a raw score and its breakdown table used to be the only always-present
 * explanation — the plain-English conclusion only existed behind the optional, manually
 * triggered AI Summary button. This covers the deterministic "What this means" line
 * (App\Support\ScoreDiagnosis) that's now always shown, and the Recommended action block
 * naming the same dominant signal instead of being a disconnected canned string.
 */
class ScoreDiagnosisDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_the_page_names_the_dominant_driver_without_needing_an_ai_summary(): void
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
                ['signal_type_code' => 'RAINFALL', 'raw_value' => 10, 'unit' => 'mm', 'normalized_score' => 20, 'weight' => 0.3, 'contribution_to_final_score' => 12.0],
                ['signal_type_code' => 'STANDING_WATER', 'raw_value' => 90, 'unit' => '%', 'normalized_score' => 90, 'weight' => 0.7, 'contribution_to_final_score' => 60.0],
            ],
            'calculated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'MALARIA_RISK']));

        $response->assertOk();
        $response->assertSee('What this means');
        $response->assertSee('driven mainly by STANDING_WATER', false);
        $response->assertSee('Based on STANDING_WATER being the main driver above.', false);
        $response->assertSee('Distribute RDTs and pre-position ACTs', false);
    }

    public function test_no_score_yet_shows_an_honest_placeholder_instead_of_a_conclusion(): void
    {
        $user = User::factory()->create();
        $region = Region::query()->first();

        $response = $this->actingAs($user)->get(route('regions.show', ['region' => $region, 'index' => 'MALARIA_RISK']));

        $response->assertOk();
        $response->assertSee("Not enough data yet to say what's driving this score.", false);
    }
}
