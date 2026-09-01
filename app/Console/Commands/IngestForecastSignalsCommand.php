<?php

namespace App\Console\Commands;

use App\Jobs\IngestRegionForecastJob;
use App\Models\Region;
use App\Services\Ingestion\ForecastIngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The forecast counterpart of signals:ingest (BUILD_PLAN.md T4). Pulls each configured forecast
 * source's forward series for every active region, as of today, out to the horizon.
 */
class IngestForecastSignalsCommand extends Command
{
    protected $signature = 'signals:ingest-forecast
        {--region= : Only ingest for this region_id}
        {--source= : Only these forecast source codes, comma-separated (e.g. RIVER_DISCHARGE). Defaults to every configured forecast source.}
        {--horizon=14 : Forecast horizon in days from today}
        {--sync : Run inline instead of queueing}';

    protected $description = 'Ingest the forward forecast series of every configured forecast source for every active region.';

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

        $sources = collect(config('ingestion.forecast_sources', []))
            ->filter(fn ($serviceClass) => $requestedSources === null
                || in_array(app($serviceClass)->signalTypeCode(), $requestedSources, true))
            ->values()
            ->all();

        $dispatched = 0;

        foreach ($sources as $serviceClass) {
            /** @var ForecastIngestionService $service */
            $service = app($serviceClass);

            foreach ($regions as $region) {
                if ($this->option('sync')) {
                    $rows = $service->ingestForecastForRegion($region, $issuedAt, $horizon);
                    $this->line($rows->isNotEmpty()
                        ? "  {$service->signalTypeCode()} — {$region->name}: {$rows->count()} forecast days"
                        : "  {$service->signalTypeCode()} — {$region->name}: no coverage");
                } else {
                    IngestRegionForecastJob::dispatch($serviceClass, $region->region_id, $issuedAt->toDateString(), $horizon);
                }

                $dispatched++;
            }
        }

        $this->info($this->option('sync')
            ? "Ingested {$dispatched} region/forecast-source combinations synchronously."
            : "Queued {$dispatched} region/forecast-source jobs. Run `php artisan queue:work` to process them.");

        return self::SUCCESS;
    }
}
