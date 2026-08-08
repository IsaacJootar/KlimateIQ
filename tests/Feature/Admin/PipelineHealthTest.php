<?php

namespace Tests\Feature\Admin;

use App\Jobs\IngestRegionSignalJob;
use App\Models\Region;
use App\Models\User;
use App\Models\UserRegionSubscription;
use App\Services\Ingestion\RainfallIngestionService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PipelineHealthTest extends TestCase
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

    /**
     * A fresh seed has zero active regions — nobody's ingested anything and nobody's
     * subscribed to anything yet. A subscription is the cheapest way to make one active.
     */
    private function activateARegion(): Region
    {
        $region = Region::query()->first();
        UserRegionSubscription::create(['user_id' => User::factory()->create()->id, 'region_id' => $region->region_id]);

        return $region->fresh();
    }

    /**
     * Inserts a failed_jobs row in the same shape Laravel's own queue worker writes —
     * displayName + a genuinely serialized command — so this test exercises the same
     * parsing path the controller uses on real failures, not a shortcut structure.
     */
    private function insertFailedJob(string $regionId, string $exceptionMessage): string
    {
        $uuid = (string) Str::uuid();
        $job = new IngestRegionSignalJob(RainfallIngestionService::class, (int) $regionId, '2026-07-01', '2026-07-07');

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => IngestRegionSignalJob::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => 3,
                'data' => [
                    'commandName' => IngestRegionSignalJob::class,
                    'command' => serialize($job),
                ],
            ]),
            'exception' => $exceptionMessage."\nSome stack trace line",
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    public function test_non_admins_cannot_view_pipeline_health(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.pipeline.index'))->assertForbidden();
    }

    public function test_admins_see_the_freshness_grid_for_active_regions(): void
    {
        $admin = $this->admin();
        $region = $this->activateARegion();

        $response = $this->actingAs($admin)->get(route('admin.pipeline.index'));

        $response->assertOk();
        $response->assertSee($region->name);
        $response->assertSee('RAINFALL');
    }

    public function test_run_now_dispatches_a_job_per_active_region_per_source(): void
    {
        // Faked so this asserts dispatch behavior without actually executing ingestion —
        // the test environment's QUEUE_CONNECTION is "sync", which would otherwise run
        // every job for real, hitting live external APIs from a test.
        Queue::fake();

        $admin = $this->admin();
        $region = $this->activateARegion();
        $sourceCount = count(config('ingestion.sources'));

        $this->actingAs($admin)->post(route('admin.pipeline.run-now'))->assertRedirect();

        Queue::assertPushed(IngestRegionSignalJob::class, $sourceCount);
        Queue::assertPushed(fn (IngestRegionSignalJob $job) => $job->regionId === $region->region_id);
    }

    public function test_a_real_failure_is_parsed_and_shown_with_its_source_and_region(): void
    {
        $admin = $this->admin();
        $region = $this->activateARegion();
        $this->insertFailedJob($region->region_id, 'RuntimeException: NASA POWER request failed with status 500.');

        $response = $this->actingAs($admin)->get(route('admin.pipeline.index'));

        $response->assertSee($region->name);
        $response->assertSee('RAINFALL');
        $response->assertSee('NASA POWER request failed with status 500.');
    }

    public function test_retrying_a_failure_requeues_it_and_removes_it_from_failed_jobs(): void
    {
        $admin = $this->admin();
        $region = $this->activateARegion();
        $uuid = $this->insertFailedJob($region->region_id, 'RuntimeException: transient failure.');

        $this->actingAs($admin)->post(route('admin.pipeline.failures.retry', $uuid))->assertRedirect();

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count());
    }
}
