<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Clarity Pass B3 — the sector home. One screen per followed sector: every index in it as a
 * status card, plus one headline count of the LGAs needing attention across the whole sector.
 */
class SectorHomeTest extends TestCase
{
    use RefreshDatabase;

    private function score(int $regionId, string $indexCode, ?float $score, string $periodStart = '2026-08-19'): void
    {
        $index = ScoringIndex::query()->where('code', $indexCode)->firstOrFail();

        RegionScore::query()->create([
            'region_id' => $regionId,
            'index_id' => $index->index_id,
            'period_start' => Carbon::parse($periodStart),
            'period_end' => Carbon::parse($periodStart)->addDays(6),
            'score' => $score,
            'scoring_strategy' => 'formula',
            'breakdown' => [],
            'calculated_at' => now(),
        ]);
    }

    public function test_it_shows_every_index_in_the_sector_as_a_card(): void
    {
        $user = User::factory()->create();
        [$r1, $r2] = Region::query()->take(2)->get();
        $user->regionSubscriptions()->create(['region_id' => $r1->region_id]);
        $user->regionSubscriptions()->create(['region_id' => $r2->region_id]);
        $user->sectorSubscriptions()->create(['sector_id' => Sector::query()->where('code', 'PUBLIC_HEALTH')->value('sector_id')]);

        $this->score($r1->region_id, 'MALARIA_RISK', 71.0);   // red
        $this->score($r2->region_id, 'MALARIA_RISK', 20.0);   // green
        $this->score($r1->region_id, 'HEAT_STRESS_RISK', 12.0);

        $response = $this->actingAs($user)->get(route('sectors.show', 'PUBLIC_HEALTH'));

        $response->assertOk()
            ->assertSee('Public Health &amp; Epidemiology', false)
            ->assertSee('Malaria Risk', false)
            ->assertSee('Heat Stress Risk', false)
            // one of the two LGAs is red on malaria -> needs attention
            ->assertSee('1 of your 2 LGAs need attention', false)
            ->assertSee('1 of 2 LGAs need attention', false)   // the malaria card
            ->assertSee('Highest:', false);
    }

    public function test_a_clear_sector_says_so(): void
    {
        $user = User::factory()->create();
        $r = Region::query()->first();
        $user->regionSubscriptions()->create(['region_id' => $r->region_id]);
        $user->sectorSubscriptions()->create(['sector_id' => Sector::query()->where('code', 'AGRICULTURE')->value('sector_id')]);

        $this->score($r->region_id, 'DROUGHT_RISK', 15.0);
        $this->score($r->region_id, 'AGRICULTURE_STRESS', 22.0);

        $this->actingAs($user)->get(route('sectors.show', 'AGRICULTURE'))
            ->assertOk()
            ->assertSee('All 1 LGA is clear', false);
    }

    public function test_each_card_links_to_the_full_index_view(): void
    {
        $user = User::factory()->create();
        $user->sectorSubscriptions()->create(['sector_id' => Sector::query()->where('code', 'AGRICULTURE')->value('sector_id')]);

        $this->actingAs($user)->get(route('sectors.show', 'AGRICULTURE'))
            ->assertOk()
            ->assertSee(route('regions.index', ['index' => 'DROUGHT_RISK']), false);
    }

    public function test_a_sector_the_user_does_not_follow_shows_a_prompt_to_add_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('sectors.show', 'EMERGENCY_RESPONSE'))
            ->assertOk()
            ->assertSee("don't follow", false)
            ->assertSee(route('coverage.edit'), false);
    }

    public function test_the_dashboard_header_links_the_sector_name_to_its_home(): void
    {
        $user = User::factory()->create();
        $user->sectorSubscriptions()->create(['sector_id' => Sector::query()->where('code', 'AGRICULTURE')->value('sector_id')]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('sectors.show', 'AGRICULTURE'), false);
    }

    public function test_an_unknown_sector_code_is_a_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/sectors/NOT_A_SECTOR')->assertNotFound();
    }
}
