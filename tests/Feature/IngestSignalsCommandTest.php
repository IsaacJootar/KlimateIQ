<?php

namespace Tests\Feature;

use App\Jobs\IngestRegionSignalJob;
use App\Models\Region;
use App\Models\User;
use App\Models\UserRegionSubscription;
use App\Services\Ingestion\ElevationIngestionService;
use App\Services\Ingestion\RainfallIngestionService;
use App\Services\Ingestion\StandingWaterIngestionService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IngestSignalsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    private function activate(): Region
    {
        $region = Region::query()->first();
        UserRegionSubscription::create(['user_id' => User::factory()->create()->id, 'region_id' => $region->region_id]);

        return $region->fresh();
    }

    public function test_with_no_source_option_it_dispatches_every_configured_source(): void
    {
        Queue::fake();
        $this->activate();
        $sourceCount = count(config('ingestion.sources'));

        $this->artisan('signals:ingest')->assertSuccessful();

        Queue::assertPushed(IngestRegionSignalJob::class, $sourceCount);
    }

    public function test_the_source_option_dispatches_only_the_matching_sources(): void
    {
        Queue::fake();
        $this->activate();

        $this->artisan('signals:ingest --source=RAINFALL,STANDING_WATER')->assertSuccessful();

        Queue::assertPushed(IngestRegionSignalJob::class, 2);
        Queue::assertPushed(fn (IngestRegionSignalJob $job) => $job->serviceClass === RainfallIngestionService::class);
        Queue::assertPushed(fn (IngestRegionSignalJob $job) => $job->serviceClass === StandingWaterIngestionService::class);
        Queue::assertNotPushed(fn (IngestRegionSignalJob $job) => $job->serviceClass === ElevationIngestionService::class);
    }

    public function test_the_source_option_is_case_insensitive(): void
    {
        Queue::fake();
        $this->activate();

        $this->artisan('signals:ingest --source=rainfall')->assertSuccessful();

        Queue::assertPushed(IngestRegionSignalJob::class, 1);
    }

    public function test_an_unrecognized_source_code_dispatches_nothing(): void
    {
        Queue::fake();
        $this->activate();

        $this->artisan('signals:ingest --source=NOT_A_REAL_SOURCE')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
