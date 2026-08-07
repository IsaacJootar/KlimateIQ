<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_a_user_can_switch_to_dark_mode(): void
    {
        $user = User::factory()->create(['theme' => 'light']);

        $response = $this->actingAs($user)->post(route('preferences.theme'), ['theme' => 'dark']);

        $response->assertRedirect();
        $this->assertSame('dark', $user->fresh()->theme);
    }

    public function test_the_dark_class_is_rendered_on_html_when_a_users_theme_is_dark(): void
    {
        $user = User::factory()->create(['theme' => 'dark']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertSee('class="dark"', false);
    }

    public function test_an_invalid_theme_value_is_rejected(): void
    {
        $user = User::factory()->create(['theme' => 'light']);

        $response = $this->actingAs($user)->post(route('preferences.theme'), ['theme' => 'blue']);

        $response->assertSessionHasErrors('theme');
        $this->assertSame('light', $user->fresh()->theme);
    }
}
