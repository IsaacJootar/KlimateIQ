<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'designation' => 'RESEARCHER',
            'state' => 'Lagos',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'designation' => 'Researcher', 'state' => 'Lagos']);
    }

    public function test_registration_requires_a_role_and_state(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors(['designation', 'state']);
        $this->assertGuest();
    }

    public function test_registration_with_other_role_requires_a_description(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'designation' => 'OTHER',
            'state' => 'Lagos',
        ]);

        $response->assertSessionHasErrors(['other_designation']);
        $this->assertGuest();
    }

    public function test_registration_with_other_role_stores_the_free_text_description(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'designation' => 'OTHER',
            'other_designation' => 'Community Health Volunteer',
            'state' => 'Lagos',
        ]);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'designation' => 'Community Health Volunteer']);
    }
}
