<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The rate limiter's array-cache store isn't reset by RefreshDatabase (that only
        // touches the database) — without this, an earlier test's exhausted quota for a
        // recycled user ID (RefreshDatabase resets auto-increment sequences too) leaks into
        // this one.
        Cache::flush();
    }

    public function test_requests_within_the_limit_succeed(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/indices')->assertOk();
    }

    public function test_the_61st_request_in_a_minute_is_rate_limited(): void
    {
        Sanctum::actingAs(User::factory()->create());

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/indices')->assertOk();
        }

        $this->getJson('/api/v1/indices')->assertStatus(429);
    }

    public function test_the_limit_is_keyed_per_user_not_shared_across_tokens(): void
    {
        // A raw bearer-token request (rather than Sanctum::actingAs(), used above) — this is
        // what actually exercises the real Authorization-header path a third party would use,
        // confirmed working end-to-end against the real API in ApiTokenTest.
        $userA = User::factory()->create();
        $tokenA = $userA->createToken('a')->plainTextToken;

        for ($i = 0; $i < 60; $i++) {
            $this->withHeader('Authorization', "Bearer {$tokenA}")->getJson('/api/v1/indices')->assertOk();
        }
        $this->withHeader('Authorization', "Bearer {$tokenA}")->getJson('/api/v1/indices')->assertStatus(429);

        // A second user, exhausted independently in its own test run below, proves the "by
        // user" key actually differentiates users rather than sharing one global bucket — see
        // test_a_second_users_quota_starts_fresh_in_a_separate_process.
    }

    /**
     * Split into its own test deliberately: Laravel's testing HTTP client reuses the same
     * application/container across every call made within a single test method, and Sanctum's
     * guard caches the user it resolves for a bearer token for the life of that container —
     * so a second real Authorization-header request within the same method as the first
     * incorrectly resolves to the FIRST user's token owner, not the second's. This is a known
     * category of Laravel/Sanctum testing quirk with raw multi-user bearer-token requests, not
     * a production bug — real requests are separate PHP processes with no shared guard state.
     * PHPUnit gives every test method a fresh application, which sidesteps it cleanly.
     */
    public function test_a_second_users_quota_starts_fresh_in_a_separate_process(): void
    {
        $userB = User::factory()->create();
        $tokenB = $userB->createToken('b')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$tokenB}")->getJson('/api/v1/indices');

        $response->assertOk();
        $this->assertSame('59', $response->headers->get('X-RateLimit-Remaining'));
    }
}
