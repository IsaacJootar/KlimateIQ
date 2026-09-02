<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\SignalType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Region dropdown on the Thresholds page is scoped to the user's own coverage — a threshold
 * only makes sense on a region they watch. With no coverage set, it falls back to active
 * regions, never all 774 seeded LGAs.
 */
class ThresholdRegionPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_the_users_coverage_regions(): void
    {
        $mine = Region::query()->orderBy('region_id')->first();
        $notMine = Region::query()->orderBy('region_id')->skip(5)->first();

        $user = User::factory()->create();
        $user->regionSubscriptions()->create(['region_id' => $mine->region_id]);

        $this->actingAs($user)->get(route('thresholds.index'))
            ->assertOk()
            ->assertSee($mine->name)
            ->assertDontSee("{$notMine->name}, {$notMine->state}", false)
            ->assertSee('Your coverage regions');
    }

    public function test_with_no_coverage_it_falls_back_to_active_regions_not_all_of_them(): void
    {
        $active = Region::query()->orderBy('region_id')->first();
        $active->signals()->create([
            'signal_type_id' => SignalType::query()->value('signal_type_id'),
            'period_start' => '2026-08-10', 'period_end' => '2026-08-16', 'value' => 1, 'source' => 'test', 'ingested_at' => now(),
        ]);
        $dormant = Region::query()->whereDoesntHave('signals')->whereDoesntHave('subscribers')->orderBy('region_id')->first();

        $user = User::factory()->create();

        $this->actingAs($user)->get(route('thresholds.index'))
            ->assertOk()
            ->assertSee($active->name)
            ->assertDontSee("{$dormant->name}, {$dormant->state}", false)
            ->assertSee('Every active region');
    }
}
