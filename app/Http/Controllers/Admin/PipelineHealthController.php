<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IngestRegionSignalJob;
use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\SignalType;
use App\Support\ApiCapacityLimits;
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
            'queue' => $this->queueSnapshot(),
            'capacity' => $this->capacitySnapshot(),
        ]);
    }

    /**
     * "At your current scale that's a non-issue" only stays true if someone's watching for when
     * it stops being true. Real ingestions in the last 24h per source, against that source's own
     * published free-tier limit (App\Support\ApiCapacityLimits) — so a scale problem shows up as
     * a specific, sourced number crossing a specific, sourced threshold, not a vague feeling.
     *
     * ingested_at counts are a proxy for real API calls made, not a perfect one — a request that
     * failed before writing a row (see Recent Failures) isn't counted here. That undercount is
     * the safe direction: this could only ever show usage as lower than reality, never higher.
     *
     * @return array<int, array{code: string, provider: string, callsLast24h: int, dailyLimit: ?int, percent: ?float, warning: bool, recommendation: string}>
     */
    private function capacitySnapshot(): array
    {
        $signalTypesByCode = SignalType::query()->get()->keyBy('code');

        $callsLast24h = RegionSignal::query()
            ->where('ingested_at', '>', now()->subDay())
            ->get()
            ->groupBy('signal_type_id')
            ->map->count();

        return collect(ApiCapacityLimits::all())
            ->map(function (array $limit, string $code) use ($signalTypesByCode, $callsLast24h) {
                $signalType = $signalTypesByCode->get($code);
                $calls = $signalType ? ($callsLast24h->get($signalType->signal_type_id) ?? 0) : 0;
                $percent = $limit['dailyLimit'] ? round(($calls / $limit['dailyLimit']) * 100, 1) : null;

                return [
                    'code' => $code,
                    'provider' => $limit['provider'],
                    'callsLast24h' => $calls,
                    'dailyLimit' => $limit['dailyLimit'],
                    'percent' => $percent,
                    'warning' => $percent !== null && $percent >= (ApiCapacityLimits::WARNING_THRESHOLD * 100),
                    'recommendation' => $limit['recommendation'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * A visible answer to "is a worker actually keeping up" — this app has no queue worker
     * dashboard (Horizon et al.), and a silent backlog is exactly what happened once already:
     * jobs piled up for hours with nothing surfacing it short of querying the database by hand.
     *
     * @return array{total: int, oldestAgeMinutes: ?int, byType: \Illuminate\Support\Collection}
     */
    private function queueSnapshot(): array
    {
        $rows = DB::table('jobs')->orderBy('created_at')->get(['payload', 'created_at']);

        $byType = $rows
            ->map(fn ($row) => json_decode($row->payload, true)['displayName'] ?? 'Unknown')
            ->countBy()
            ->sortDesc();

        $oldest = $rows->first();

        return [
            'total' => $rows->count(),
            // Carbon 3 returns a signed diff by default (negative here, since the argument is
            // in the past) — abs() it, this is an age, not a direction.
            'oldestAgeMinutes' => $oldest ? abs(now()->diffInMinutes(\Illuminate\Support\Carbon::createFromTimestamp($oldest->created_at))) : null,
            'byType' => $byType,
        ];
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
