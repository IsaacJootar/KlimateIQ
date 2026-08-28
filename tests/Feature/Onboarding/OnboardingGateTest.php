<?php

namespace Tests\Feature\Onboarding;

use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M3: App\Http\Middleware\EnsureOnboarded pushes a not-yet-onboarded user into the setup
 * wizard before they can use the rest of the app.
 */
class OnboardingGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_a_not_yet_onboarded_user_is_redirected_from_the_dashboard(): void
    {
        $user = User::factory()->unonboarded()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertRedirect(route('onboarding.show'));
    }

    public function test_a_not_yet_onboarded_user_is_redirected_from_other_app_pages(): void
    {
        $user = User::factory()->unonboarded()->create();

        $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('onboarding.show'));
        $this->actingAs($user)->get(route('regions.index'))->assertRedirect(route('onboarding.show'));
        $this->actingAs($user)->get(route('coverage.edit'))->assertRedirect(route('onboarding.show'));
    }

    public function test_the_wizard_itself_is_reachable_while_not_onboarded(): void
    {
        $user = User::factory()->unonboarded()->create();

        $this->actingAs($user)->get(route('onboarding.show'))->assertOk();
    }

    public function test_a_not_yet_onboarded_user_can_still_sign_out(): void
    {
        $user = User::factory()->unonboarded()->create();

        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_an_onboarded_user_reaches_the_dashboard_normally(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_visiting_the_wizard_when_already_onboarded_bounces_to_the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('onboarding.show'))->assertRedirect(route('dashboard'));
    }

    public function test_registration_sends_a_new_user_into_the_wizard(): void
    {
        $response = $this->post(route('register'), [
            'name' => 'New Officer',
            'email' => 'new.officer@example.test',
            'password' => 'password-1234',
            'password_confirmation' => 'password-1234',
            'designation' => 'LGA_OFFICER',
            'state' => 'Kano',
            'new_agency_name' => 'Kano State Ministry of Health',
        ]);

        $response->assertRedirect(route('onboarding.show'));
        $this->assertNull(User::where('email', 'new.officer@example.test')->first()->onboarded_at);
    }
}
