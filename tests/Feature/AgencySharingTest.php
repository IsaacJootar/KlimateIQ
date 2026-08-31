<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\SavedView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgencySharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_saved_view_shared_with_an_agency_is_visible_to_other_members(): void
    {
        $agency = Agency::first();
        $owner = User::factory()->create(['agency_id' => $agency->agency_id]);
        $colleague = User::factory()->create(['agency_id' => $agency->agency_id]);

        SavedView::query()->create([
            'user_id' => $owner->id,
            'agency_id' => $agency->agency_id,
            'name' => 'Shared watchlist',
            'region_ids' => [1, 2],
        ]);

        $response = $this->actingAs($colleague)->get(route('saved-views.index'));

        $response->assertSee('Shared watchlist');
    }

    public function test_a_private_saved_view_is_not_visible_to_agency_colleagues(): void
    {
        $agency = Agency::first();
        $owner = User::factory()->create(['agency_id' => $agency->agency_id]);
        $colleague = User::factory()->create(['agency_id' => $agency->agency_id]);

        SavedView::query()->create([
            'user_id' => $owner->id,
            'agency_id' => null,
            'name' => 'Private watchlist',
            'region_ids' => [1, 2],
        ]);

        $response = $this->actingAs($colleague)->get(route('saved-views.index'));

        $response->assertDontSee('Private watchlist');
    }

    public function test_a_saved_view_is_not_visible_to_users_in_a_different_agency(): void
    {
        $agencies = Agency::take(2)->get();
        $owner = User::factory()->create(['agency_id' => $agencies[0]->agency_id]);
        $outsider = User::factory()->create(['agency_id' => $agencies[1]->agency_id]);

        SavedView::query()->create([
            'user_id' => $owner->id,
            'agency_id' => $agencies[0]->agency_id,
            'name' => 'Other agency watchlist',
            'region_ids' => [1, 2],
        ]);

        $response = $this->actingAs($outsider)->get(route('saved-views.index'));

        $response->assertDontSee('Other agency watchlist');
    }

    public function test_deleting_a_shared_saved_view_is_restricted_to_its_owner(): void
    {
        $agency = Agency::first();
        $owner = User::factory()->create(['agency_id' => $agency->agency_id]);
        $colleague = User::factory()->create(['agency_id' => $agency->agency_id]);

        $view = SavedView::query()->create([
            'user_id' => $owner->id,
            'agency_id' => $agency->agency_id,
            'name' => 'Shared watchlist',
            'region_ids' => [1, 2],
        ]);

        $response = $this->actingAs($colleague)->delete(route('saved-views.destroy', $view));

        $response->assertForbidden();
        $this->assertDatabaseHas('saved_views', ['saved_view_id' => $view->saved_view_id]);
    }
}
