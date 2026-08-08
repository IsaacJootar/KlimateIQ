<?php

namespace App\Jobs;

use App\Models\Region;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class IngestRegionSignalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    // Most sources return in well under a minute. Vegetation (AppEEARS) submits an async
    // task and polls until it completes, which in practice takes ~1 minute but can run
    // longer — this ceiling only matters for that source; it's a no-op for fast ones.
    public int $timeout = 300;

    /**
     * @param  class-string<\App\Services\Ingestion\SignalIngestionService>  $serviceClass
     */
    public function __construct(
        public string $serviceClass,
        public int $regionId,
        public string $periodStart,
        public string $periodEnd,
    ) {}

    public function handle(): void
    {
        $region = Region::query()->findOrFail($this->regionId);

        /** @var \App\Services\Ingestion\SignalIngestionService $service */
        $service = app($this->serviceClass);

        $service->ingestForRegion(
            $region,
            Carbon::parse($this->periodStart),
            Carbon::parse($this->periodEnd),
        );
    }
}
