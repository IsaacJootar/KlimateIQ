<?php

namespace App\Jobs;

use App\Models\Region;
use App\Services\Ingestion\ForecastIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * The forecast counterpart of IngestRegionSignalJob — one forecast source, one region, one
 * issue date. Same retry/backoff posture; the flood API returns fast so the long timeout that
 * IngestRegionSignalJob carries for AppEEARS isn't needed here.
 */
class IngestRegionForecastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 120;

    /**
     * @param  class-string<ForecastIngestionService>  $serviceClass
     */
    public function __construct(
        public string $serviceClass,
        public int $regionId,
        public string $issuedAt,
        public int $horizonDays,
    ) {}

    public function handle(): void
    {
        $region = Region::query()->findOrFail($this->regionId);

        /** @var ForecastIngestionService $service */
        $service = app($this->serviceClass);

        $service->ingestForecastForRegion($region, Carbon::parse($this->issuedAt), $this->horizonDays);
    }
}
