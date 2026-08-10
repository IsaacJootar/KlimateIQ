<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Sentry (bootstrap/app.php -> Integration::handles($exceptions)) must be a true no-op with no
 * SENTRY_LARAVEL_DSN configured (the state of every environment until one is actually supplied)
 * — this just confirms wiring it into the exception handler didn't change how exceptions are
 * rendered to a real request.
 */
class SentryIntegrationTest extends TestCase
{
    public function test_config_is_registered_and_inert_without_a_dsn(): void
    {
        $this->assertEmpty(config('sentry.dsn'));
    }

    public function test_a_404_still_renders_normally_with_sentry_wired_in(): void
    {
        $response = $this->getJson('/api/v1/this-route-does-not-exist');

        $response->assertStatus(404);
    }

    public function test_an_unauthenticated_web_request_still_redirects_normally(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect(route('login'));
    }
}
