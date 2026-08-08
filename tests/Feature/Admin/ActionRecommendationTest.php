<?php

namespace Tests\Feature\Admin;

use App\Models\IndexActionRecommendation;
use App\Models\ScoringIndex;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionRecommendationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['platform_role' => 'PLATFORM_ADMIN'])->save();

        return $user;
    }

    public function test_non_admins_cannot_view_recommended_actions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.actions.index'))->assertForbidden();
    }

    public function test_admins_see_the_seeded_text_for_each_band(): void
    {
        $admin = $this->admin();
        $index = ScoringIndex::query()->where('code', 'MALARIA_RISK')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('admin.actions.index', ['index' => $index->index_id]));

        $response->assertOk();
        $response->assertSee('Continue routine surveillance', false);
        $response->assertSee('Notify the state malaria programme within 48 hours', false);
    }

    public function test_updating_persists_new_text_for_every_band(): void
    {
        $admin = $this->admin();
        $index = ScoringIndex::query()->where('code', 'MALARIA_RISK')->firstOrFail();

        $response = $this->actingAs($admin)->put(route('admin.actions.update', $index), [
            'action_text' => [
                'green' => 'Updated green text.',
                'amber' => 'Updated amber text.',
                'red' => 'Updated red text.',
            ],
        ]);

        $response->assertRedirect();

        $this->assertSame('Updated green text.', IndexActionRecommendation::query()->where('index_id', $index->index_id)->where('risk_band', 'green')->value('action_text'));
        $this->assertSame('Updated amber text.', IndexActionRecommendation::query()->where('index_id', $index->index_id)->where('risk_band', 'amber')->value('action_text'));
        $this->assertSame('Updated red text.', IndexActionRecommendation::query()->where('index_id', $index->index_id)->where('risk_band', 'red')->value('action_text'));
    }

    public function test_a_blank_band_is_rejected(): void
    {
        $admin = $this->admin();
        $index = ScoringIndex::query()->where('code', 'MALARIA_RISK')->firstOrFail();

        $response = $this->actingAs($admin)->put(route('admin.actions.update', $index), [
            'action_text' => [
                'green' => 'Fine.',
                'amber' => '',
                'red' => 'Also fine.',
            ],
        ]);

        $response->assertSessionHasErrors('action_text.amber');
    }
}
