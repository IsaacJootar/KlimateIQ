<?php

namespace App\Console\Commands;

use App\Jobs\IngestRegionSignalJob;
use App\Models\Region;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class IngestSignalsCommand extends Command
{
    protected $signature = 'signals:ingest
        {--region= : Only ingest for this region_id}
        {--sync : Run inline instead of queueing (useful for a first proof-of-pipeline run)}';

    protected $description = 'Ingest the last complete week of every configured signal source for every region.';

    public function handle(): int
    {
        // NASA POWER (and most reanalysis sources) lag a few days behind real time, so
        // "last complete week" means 7-13 days ago, not the current week.
        $periodEnd = Carbon::now()->subDays(6)->startOfDay();
        $periodStart = $periodEnd->copy()->subDays(6);

        $regions = $this->option('region')
            ? Region::query()->where('region_id', $this->option('region'))->get()
            : Region::query()->get();

        $sources = config('ingestion.sources', []);
        $dispatched = 0;

        foreach ($sources as $serviceClass) {
            /** @var \App\Services\Ingestion\SignalIngestionService $service */
            $service = app($serviceClass);

            foreach ($regions as $region) {
                if ($this->option('sync')) {
                    $signal = $service->ingestForRegion($region, $periodStart, $periodEnd);
                    $this->line($signal
                        ? "  {$service->signalTypeCode()} — {$region->name}: {$signal->value} {$signal->signalType->unit}"
                        : "  {$service->signalTypeCode()} — {$region->name}: no data");
                } else {
                    IngestRegionSignalJob::dispatch(
                        $serviceClass,
                        $region->region_id,
                        $periodStart->toDateString(),
                        $periodEnd->toDateString(),
                    );
                }

                $dispatched++;
            }
        }

        $this->info($this->option('sync')
            ? "Ingested {$dispatched} region/source combinations synchronously."
            : "Queued {$dispatched} region/source jobs. Run `php artisan queue:work` to process them.");

        return self::SUCCESS;
    }
}
