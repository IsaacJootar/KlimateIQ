<?php

namespace Tests\Feature;

use App\Models\ScoringIndex;
use App\Models\User;
use App\Models\UserIndexSubscription;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for a real bug: the Dashboard's "default index" used to be whichever
 * subscribed index had the lowest index_id — in practice always Malaria Risk (seeded first),
 * no matter what else was also selected. Reported live as "changing my coverage looks like it
 * does nothing."
 */
class DashboardDefaultIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    private function subscribeToIndices(User $user, array $codes): void
    {
        foreach (ScoringIndex::whereIn('code', $codes)->get() as $index) {
            UserIndexSubscription::create(['user_id' => $user->id, 'index_id' => $index->index_id, 'wants_alerts' => true]);
        }
    }

    public function test_a_single_subscribed_index_is_used_regardless_of_its_id(): void
    {
        $user = User::factory()->create();
        $this->subscribeToIndices($user, ['FLOOD_RISK']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertSee('Flood Risk Index');
        $response->assertDontSee('Malaria Risk Index');
    }

    public function test_malaria_no_longer_always_wins_when_multiple_indices_are_subscribed(): void
    {
        $user = User::factory()->create();
        // Malaria Risk has the lowest index_id of any seeded index — this is exactly the
        // combination that silently always defaulted to it under the old logic.
        $this->subscribeToIndices($user, ['MALARIA_RISK', 'DROUGHT_RISK']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        // Neither is Composite, so the tiebreak is alphabetical: "Drought" before "Malaria".
        $response->assertSee('Drought Risk Index');
    }

    public function test_composite_pressure_is_preferred_when_present_among_multiple_selections(): void
    {
        $user = User::factory()->create();
        $this->subscribeToIndices($user, ['MALARIA_RISK', 'FLOOD_RISK', 'COMPOSITE_PRESSURE']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertSee('Composite Climate-Health Pressure Index');
    }

    public function test_no_subscriptions_defaults_to_composite_pressure(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertSee('Composite Climate-Health Pressure Index');
    }

    public function test_a_tab_is_shown_per_subscribed_index_and_clicking_one_switches_the_view(): void
    {
        $user = User::factory()->create();
        $this->subscribeToIndices($user, ['MALARIA_RISK', 'FLOOD_RISK', 'DROUGHT_RISK']);

        // Default (no ?index=) lands on Drought, per the alphabetical tiebreak — this alone
        // proves the fix from DashboardController still works with tabs layered on top.
        $default = $this->actingAs($user)->get(route('dashboard'));
        $default->assertSee('pill-tab-active', false);
        $default->assertSee('Drought Risk Index');

        // Explicitly clicking the Malaria tab switches the active view to it.
        $switched = $this->actingAs($user)->get(route('dashboard', ['index' => 'MALARIA_RISK']));
        $switched->assertSee('Malaria Risk Index');
    }

    public function test_no_tabs_render_when_only_one_index_is_available(): void
    {
        $user = User::factory()->create();
        $this->subscribeToIndices($user, ['FLOOD_RISK']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertDontSee('pill-tab', false);
    }

    public function test_an_unrecognized_index_query_param_falls_back_to_the_deliberate_default(): void
    {
        $user = User::factory()->create();
        $this->subscribeToIndices($user, ['MALARIA_RISK', 'COMPOSITE_PRESSURE']);

        $response = $this->actingAs($user)->get(route('dashboard', ['index' => 'NOT_A_REAL_CODE']));

        $response->assertSee('Composite Climate-Health Pressure Index');
    }
}
