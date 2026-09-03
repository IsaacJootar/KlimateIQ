<?php

namespace App\Console\Commands;

use App\Jobs\IngestRegionEnsembleJob;
use App\Models\Region;
use App\Services\Ingestion\EnsembleForecastIngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The ensemble counterpart of signals:ingest-forecast (BUILD_PLAN.md T5). Pulls each configured
 * ensemble source's member series for every active region, as of today, out to the horizon —
 * into region_forecast_signals alongside the deterministic 'control' series.
 */
class IngestEnsembleSignalsCommand extends Command
{
    protected $signature = 'signals:ingest-ensemble
        {--region= : Only ingest for this region_id}
        {--source= : Only these ensemble source codes, comma-separated (e.g. RIVER_DISCHARGE). Defaults to every configured ensemble source.}
        {--horizon=14 : Forecast horizon in days from today}
        {--sync : Run inline instead of queueing}';

    protected $description = 'Ingest the ensemble member series of every configured ensemble source for every active region.';

    public function handle(): int
    {
        $issuedAt = Carbon::now()->startOfDay();
        $horizon = (int) $this->option('horizon');

        $regions = $this->option('region')
            ? Region::query()->where('region_id', $this->option('region'))->get()
            : Region::query()->active()->get();

        $requestedSources = $this->option('source')
            ? array_map('trim', explode(',', strtoupper($this->option('source'))))
            : null;

        $sources = collect(config('ingestion.ensemble_sources', []))
            ->filter(fn ($serviceClass) => $requestedSources === null
                || in_array(app($serviceClass)->signalTypeCode(), $requestedSources, true))
            ->values()
            ->all();

        $dispatched = 0;

        foreach ($sources as $serviceClass) {
            /** @var EnsembleForecastIngestionService $service */
            $service = app($serviceClass);

            foreach ($regions as $region) {
                if ($this->option('sync')) {
                    $written = $service->ingestEnsembleForRegion($region, $issuedAt, $horizon);
                    $this->line($written > 0
                        ? "  {$service->signalTypeCode()} — {$region->name}: {$written} member rows"
                        : "  {$service->signalTypeCode()} — {$region->name}: no coverage");
                } else {
                    IngestRegionEnsembleJob::dispatch($serviceClass, $region->region_id, $issuedAt->toDateString(), $horizon);
                }

                $dispatched++;
            }
        }

        $this->info($this->option('sync')
            ? "Ingested {$dispatched} region/ensemble-source combinations synchronously."
            : "Queued {$dispatched} region/ensemble-source jobs. Run `php artisan queue:work` to process them.");

        return self::SUCCESS;
    }
}
