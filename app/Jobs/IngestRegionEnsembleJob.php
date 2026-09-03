<?php

namespace App\Jobs;

use App\Models\Region;
use App\Services\Ingestion\EnsembleForecastIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * The ensemble counterpart of IngestRegionForecastJob (BUILD_PLAN.md T5) — one ensemble source,
 * one region, one issue date. A pooled 3-model weather pull is several HTTP calls, so the
 * timeout is longer than the deterministic job's.
 */
class IngestRegionEnsembleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 300;

    /**
     * @param  class-string<EnsembleForecastIngestionService>  $serviceClass
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

        /** @var EnsembleForecastIngestionService $service */
        $service = app($this->serviceClass);

        $service->ingestEnsembleForRegion($region, Carbon::parse($this->issuedAt), $this->horizonDays);
    }
}
