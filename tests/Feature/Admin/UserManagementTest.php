<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
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

        return $user->fresh();
    }

    public function test_non_admins_cannot_view_the_users_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_admins_can_view_the_users_list(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Findable Person']);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $response->assertSee('Findable Person');
    }

    public function test_search_filters_the_users_list(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Alpha Officer']);
        User::factory()->create(['name' => 'Beta Officer']);

        $response = $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'Alpha']));

        $response->assertSee('Alpha Officer');
        $response->assertDontSee('Beta Officer');
    }

    public function test_admin_can_grant_and_revoke_another_users_admin_status(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $this->actingAs($admin)->patch(route('admin.users.toggle-admin', $target));
        $this->assertTrue($target->fresh()->isPlatformAdmin());

        $this->actingAs($admin)->patch(route('admin.users.toggle-admin', $target));
        $this->assertFalse($target->fresh()->isPlatformAdmin());
    }

    public function test_admin_can_deactivate_and_reactivate_another_user(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $this->actingAs($admin)->patch(route('admin.users.toggle-active', $target));
        $this->assertTrue($target->fresh()->isDisabled());

        $this->actingAs($admin)->patch(route('admin.users.toggle-active', $target));
        $this->assertFalse($target->fresh()->isDisabled());
    }

    public function test_admin_cannot_change_their_own_admin_status(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.users.toggle-admin', $admin))->assertForbidden();
        $this->assertTrue($admin->fresh()->isPlatformAdmin());
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.users.toggle-active', $admin))->assertForbidden();
        $this->assertFalse($admin->fresh()->isDisabled());
    }

    public function test_a_deactivated_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $user->forceFill(['disabled_at' => now()])->save();

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_deactivated_users_existing_session_is_cut_off_on_the_next_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['disabled_at' => now()])->save();

        $response = $this->actingAs($user)->get(route('dashboard'));
        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
