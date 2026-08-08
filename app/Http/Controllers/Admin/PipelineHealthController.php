<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IngestRegionSignalJob;
use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\SignalType;
use App\Support\IngestionWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PipelineHealthController extends Controller
{
    // Weekly cadence (7 days) plus a buffer — past this, a region/source is flagged stale
    // rather than waiting for someone to notice scores look wrong.
    private const STALE_AFTER_DAYS = 10;

    public function index(): View
    {
        $regions = Region::query()->active()->orderBy('name')->get();
        $sources = collect(config('ingestion.sources', []))->map(fn ($class) => app($class));
        $signalTypesByCode = SignalType::query()->get()->keyBy('code');

        $latestSignals = RegionSignal::query()
            ->whereIn('region_id', $regions->pluck('region_id'))
            ->orderByDesc('ingested_at')
            ->get()
            ->groupBy(fn (RegionSignal $s) => "{$s->region_id}:{$s->signal_type_id}")
            ->map(fn ($group) => $group->first());

        $grid = $regions->map(function (Region $region) use ($sources, $signalTypesByCode, $latestSignals) {
            return [
                'region' => $region,
                'cells' => $sources->map(function ($source) use ($region, $signalTypesByCode, $latestSignals) {
                    $signalType = $signalTypesByCode->get($source->signalTypeCode());
                    $latest = $signalType ? $latestSignals->get("{$region->region_id}:{$signalType->signal_type_id}") : null;

                    return [
                        'source_code' => $source->signalTypeCode(),
                        'ingested_at' => $latest?->ingested_at,
                        'stale' => $latest === null || $latest->ingested_at->lt(now()->subDays(self::STALE_AFTER_DAYS)),
                    ];
                }),
            ];
        });

        $failures = collect(DB::table('failed_jobs')->orderByDesc('failed_at')->limit(20)->get())
            ->map(fn ($row) => $this->describeFailure($row))
            ->filter()
            ->values();

        return view('admin.pipeline.index', [
            'sources' => $sources,
            'grid' => $grid,
            'failures' => $failures,
        ]);
    }

    public function runNow(): RedirectResponse
    {
        [$periodStart, $periodEnd] = IngestionWindow::lastComplete();
        $regions = Region::query()->active()->get();
        $dispatched = 0;

        foreach (config('ingestion.sources', []) as $serviceClass) {
            foreach ($regions as $region) {
                IngestRegionSignalJob::dispatch($serviceClass, $region->region_id, $periodStart->toDateString(), $periodEnd->toDateString());
                $dispatched++;
            }
        }

        return back()->with('status', "Queued {$dispatched} ingestion jobs across {$regions->count()} active regions. A queue worker needs to be running to process them.");
    }

    public function retryFailure(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('status', 'Job re-queued — a queue worker needs to be running to process it.');
    }

    /**
     * @return array{uuid: string, region: ?string, source: ?string, message: string, failed_at: \Illuminate\Support\Carbon}|null
     */
    private function describeFailure(object $row): ?array
    {
        $payload = json_decode($row->payload, true);

        if (($payload['displayName'] ?? null) !== IngestRegionSignalJob::class) {
            return null;
        }

        /** @var IngestRegionSignalJob|null $job */
        $job = @unserialize($payload['data']['command'] ?? '');
        $region = $job ? Region::query()->find($job->regionId) : null;
        $source = $job ? (new $job->serviceClass)->signalTypeCode() : null;

        return [
            'uuid' => $row->uuid,
            'region' => $region?->name,
            'source' => $source,
            'message' => strtok($row->exception, "\n") ?: 'Unknown error',
            'failed_at' => \Illuminate\Support\Carbon::parse($row->failed_at),
        ];
    }
}
