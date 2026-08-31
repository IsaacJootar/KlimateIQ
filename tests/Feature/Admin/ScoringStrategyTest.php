<?php

namespace Tests\Feature\Admin;

use App\Models\Region;
use App\Models\User;
use App\Models\UserRegionSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoringStrategyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['platform_role' => 'PLATFORM_ADMIN'])->save();

        return $user;
    }

    private function activate(Region $region): void
    {
        UserRegionSubscription::create(['user_id' => User::factory()->create()->id, 'region_id' => $region->region_id]);
    }

    public function test_non_admins_cannot_view_scoring_strategy(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.scoring-strategy.index'))->assertForbidden();
    }

    public function test_admins_see_active_regions_and_model_availability_per_index(): void
    {
        $admin = $this->admin();
        $region = Region::query()->first();
        $this->activate($region);

        $response = $this->actingAs($admin)->get(route('admin.scoring-strategy.index'));

        $response->assertOk();
        $response->assertSee($region->name);
        $response->assertSee('No trained model exists for any index yet.');
        $response->assertSee('MALARIA_RISK');
    }

    public function test_admins_can_set_a_regions_preferred_strategy(): void
    {
        $admin = $this->admin();
        $region = Region::query()->first();

        $this->actingAs($admin)->patch(route('admin.scoring-strategy.update', $region), [
            'preferred_scoring_strategy' => 'trained_model',
        ])->assertRedirect();

        $this->assertSame('trained_model', $region->fresh()->preferred_scoring_strategy);
    }

    public function test_admins_can_clear_a_regions_preference_back_to_platform_default(): void
    {
        $admin = $this->admin();
        $region = Region::query()->first();
        $region->update(['preferred_scoring_strategy' => 'trained_model']);

        $this->actingAs($admin)->patch(route('admin.scoring-strategy.update', $region), [
            'preferred_scoring_strategy' => '',
        ])->assertRedirect();

        $this->assertNull($region->fresh()->preferred_scoring_strategy);
    }

    public function test_invalid_strategy_values_are_rejected(): void
    {
        $admin = $this->admin();
        $region = Region::query()->first();

        $this->actingAs($admin)->patch(route('admin.scoring-strategy.update', $region), [
            'preferred_scoring_strategy' => 'not_a_real_strategy',
        ])->assertSessionHasErrors('preferred_scoring_strategy');
    }
}
