<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['platform_role' => 'PLATFORM_ADMIN'])->save();

        return $user;
    }

    public function test_non_admins_cannot_view_api_tokens(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.api-tokens.index'))->assertForbidden();
    }

    public function test_admins_can_issue_a_token_and_see_the_plaintext_once(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['name' => 'Partner Agency Account']);

        $response = $this->actingAs($admin)->post(route('admin.api-tokens.store'), [
            'user_id' => $owner->id,
            'name' => 'partner-integration',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('plainTextToken');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $owner->id,
            'tokenable_type' => User::class,
            'name' => 'partner-integration',
        ]);
    }

    public function test_the_issued_token_actually_authenticates_against_the_third_party_api(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.api-tokens.store'), [
            'user_id' => $owner->id,
            'name' => 'live-check',
        ]);

        $plainTextToken = session('plainTextToken');

        $response = $this->withHeader('Authorization', "Bearer {$plainTextToken}")->getJson('/api/v1/indices');

        $response->assertOk();
    }

    public function test_admins_can_revoke_a_token(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $token = $owner->createToken('to-be-revoked');

        $this->actingAs($admin)->delete(route('admin.api-tokens.destroy', $token->accessToken->id))->assertRedirect();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_non_admins_cannot_issue_or_revoke_tokens(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $token = $owner->createToken('protected');

        $this->actingAs($user)->post(route('admin.api-tokens.store'), [
            'user_id' => $owner->id,
            'name' => 'should-not-exist',
        ])->assertForbidden();

        $this->actingAs($user)->delete(route('admin.api-tokens.destroy', $token->accessToken->id))->assertForbidden();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }
}
