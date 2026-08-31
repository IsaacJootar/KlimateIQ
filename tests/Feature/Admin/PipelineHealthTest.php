<?php

namespace Tests\Feature\Admin;

use App\Jobs\IngestRegionSignalJob;
use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\SignalType;
use App\Models\User;
use App\Models\UserRegionSubscription;
use App\Services\Ingestion\RainfallIngestionService;
use App\Services\Ingestion\VegetationIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PipelineHealthTest extends TestCase
{
    use RefreshDatabase;

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
    private function insertFailedJob(string $regionId, string $exceptionMessage, string $serviceClass = RainfallIngestionService::class): string
    {
        $uuid = (string) Str::uuid();
        $job = new IngestRegionSignalJob($serviceClass, (int) $regionId, '2026-07-01', '2026-07-07');

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

    /**
     * Inserts a jobs row in the same shape a real dispatch writes — displayName in the payload,
     * a real unix-timestamp created_at — so tests exercise the same parsing the controller uses.
     */
    private function insertQueuedJob(string $displayName, int $ageMinutes = 0): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $displayName]),
            'attempts' => 0,
            'available_at' => now()->subMinutes($ageMinutes)->timestamp,
            'created_at' => now()->subMinutes($ageMinutes)->timestamp,
        ]);
    }

    public function test_queue_shows_zero_pending_when_empty(): void
    {
        // Explicit rather than relying solely on RefreshDatabase — another test in this class
        // uses the real 'database' queue connection (queue:retry does, regardless of the
        // app-wide QUEUE_CONNECTION=sync default), and this guards against that leaking here.
        DB::table('jobs')->delete();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('admin.pipeline.index'));

        $response->assertSee('0');
        $response->assertDontSee('is a worker running?');
    }

    public function test_queue_shows_the_real_pending_count_and_breakdown_by_type(): void
    {
        $admin = $this->admin();
        $this->insertQueuedJob(IngestRegionSignalJob::class);
        $this->insertQueuedJob(IngestRegionSignalJob::class);
        $this->insertQueuedJob('App\\Listeners\\EvaluateIndexThresholds');

        $response = $this->actingAs($admin)->get(route('admin.pipeline.index'));

        $response->assertSee('3');
        $response->assertSee('2 &times; IngestRegionSignalJob', false);
        $response->assertSee('1 &times; EvaluateIndexThresholds', false);
    }

    public function test_a_stale_queue_shows_a_warning(): void
    {
        $admin = $this->admin();
        $this->insertQueuedJob(IngestRegionSignalJob::class, ageMinutes: 45);

        $response = $this->actingAs($admin)->get(route('admin.pipeline.index'));

        $response->assertSee('is a worker running?');
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

    /**
     * Regression: describeFailure() used to instantiate the failed job's service class with a
     * bare `new`, bypassing Laravel's container — fine for RainfallIngestionService (no
     * constructor deps at the time it was written), but a fatal ArgumentCountError for any
     * service that needs one injected, like VegetationIngestionService's AppEearsClient. The
     * fix is app($class) instead of new $class; this exercises a real dependency-requiring
     * service so the failures list can't silently crash the whole admin page again.
     */
    public function test_a_failure_for_a_service_with_constructor_dependencies_renders_without_crashing(): void
    {
        $admin = $this->admin();
        $region = $this->activateARegion();
        $this->insertFailedJob(
            $region->region_id,
            'RuntimeException: AppEEARS task submission failed with status 403.',
            VegetationIngestionService::class,
        );

        $response = $this->actingAs($admin)->get(route('admin.pipeline.index'));

        $response->assertOk();
        $response->assertSee('VEGETATION');
        $response->assertSee('AppEEARS task submission failed with status 403.');
    }

    public function test_capacity_shows_zero_usage_with_no_recent_calls(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('admin.pipeline.index'));

        $response->assertSee('API capacity');
        $response->assertSee('AIR_QUALITY_PM25');
        $response->assertDontSee('is above 70% of its known limit');
    }

    public function test_calls_older_than_24h_do_not_count_toward_usage(): void
    {
        $admin = $this->admin();
        $region = $this->activateARegion();
        $signalType = SignalType::where('code', 'ELEVATION')->firstOrFail();

        RegionSignal::create([
            'region_id' => $region->region_id,
            'signal_type_id' => $signalType->signal_type_id,
            'period_start' => now()->subDays(30),
            'period_end' => now()->subDays(24),
            'value' => 100,
            'source' => 'test',
            'ingested_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.pipeline.index'));

        // The one call is 2 days old, outside the 24h window — usage should read 0%, not count it.
        $response->assertSee('API capacity');
        $response->assertDontSee('is above 70% of its known limit');
    }

    public function test_usage_above_the_warning_threshold_shows_the_recommendation(): void
    {
        $admin = $this->admin();
        $region = $this->activateARegion();
        $signalType = SignalType::where('code', 'ELEVATION')->firstOrFail();

        // Elevation's known limit is 1,000/day (Open Topo Data) — the smallest of any tracked
        // source, so it's the cheapest one to genuinely cross the 70% warning threshold with.
        $rows = collect(range(1, 701))->map(fn ($i) => [
            'region_id' => $region->region_id,
            'signal_type_id' => $signalType->signal_type_id,
            'period_start' => now()->subDays($i + 100)->toDateString(),
            'period_end' => now()->subDays($i + 94)->toDateString(),
            'value' => 100,
            'source' => 'test',
            'ingested_at' => now()->subHours(1)->toDateTimeString(),
        ])->all();
        RegionSignal::insert($rows);

        $response = $this->actingAs($admin)->get(route('admin.pipeline.index'));

        $response->assertSee('is above 70% of its known limit');
        $response->assertSee('Pulled once per region on activation only', false);
    }
}
