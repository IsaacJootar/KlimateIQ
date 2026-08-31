<?php

namespace App\Http\Controllers;

use App\Models\IndexActionRecommendation;
use App\Models\Region;
use App\Models\RegionScore;
use App\Models\RegionSignal;
use App\Models\SignalType;
use App\Services\Ai\RegionScoreSummaryService;
use App\Support\IndexCoverage;
use App\Support\RiskBand;
use App\Support\ScoreDiagnosis;
use App\Support\SignalReading;
use App\Support\TrendSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegionController extends Controller
{
    public function index(): View
    {
        ['available' => $indices, 'active' => $index] = IndexCoverage::resolve(Auth::user(), request('index'));

        $latestByRegion = RegionScore::query()
            ->where('index_id', $index->index_id)
            ->orderByDesc('period_start')
            ->get()
            ->unique('region_id')
            ->keyBy('region_id');

        // Most recent score at or before 2 weeks ago, per region — the "where were we" side
        // of the trend sentence. Same shape query as $latestByRegion, just an older cutoff.
        $priorByRegion = RegionScore::query()
            ->where('index_id', $index->index_id)
            ->where('period_start', '<=', now()->subDays(14))
            ->orderByDesc('period_start')
            ->get()
            ->unique('region_id')
            ->keyBy('region_id');

        // Explicit region_ids in the URL (a saved view, or a link scoped to a specific
        // subset) win first. Otherwise this must match the Dashboard: fall back to the
        // user's own /coverage selection, and only then to every active region — this
        // page previously ignored /coverage entirely and always showed all active
        // regions regardless of what a user had actually configured, which silently
        // contradicted the "your dashboard and region list default to these" promise
        // made on the coverage page itself.
        $regionIds = array_filter(explode(',', (string) request('regions', '')));
        $subscribedRegionIds = $regionIds === [] ? Auth::user()->regionSubscriptions()->pluck('region_id')->all() : [];

        // Full ascending history per region, for the sparkline — separate from $latestByRegion/
        // $priorByRegion above, which only ever need single points, not a series.
        $historyByRegion = RegionScore::query()
            ->where('index_id', $index->index_id)
            ->orderBy('period_start')
            ->get()
            ->groupBy('region_id');

        $regions = Region::query()
            ->when(
                $regionIds !== [],
                fn ($q) => $q->whereIn('region_id', $regionIds),
                fn ($q) => $q->when(
                    $subscribedRegionIds !== [],
                    fn ($q) => $q->whereIn('region_id', $subscribedRegionIds),
                    fn ($q) => $q->active()
                )
            )
            ->orderBy('name')
            ->get()
            ->map(function (Region $region) use ($latestByRegion, $priorByRegion, $historyByRegion) {
                $score = $latestByRegion->get($region->region_id);
                $prior = $priorByRegion->get($region->region_id);
                $region->setAttribute('current_score', $score?->score);
                $region->setAttribute('risk_band', RiskBand::forScore($score?->score));
                $region->setAttribute('trend', TrendSummary::describe(
                    $score?->score !== null ? (float) $score->score : null,
                    $prior?->score !== null ? (float) $prior->score : null,
                ));
                $region->setAttribute('sparkline', $historyByRegion->get($region->region_id, collect())
                    ->pluck('score')
                    ->map(fn ($v) => $v !== null ? (float) $v : null)
                    ->take(-8)
                    ->values()
                    ->all());

                return $region;
            });

        $followedSectors = Auth::user()->sectorSubscriptions()->with('sector')->get()
            ->pluck('sector')->filter()->sortBy('sort_order')->values();

        return view('regions.index', [
            'regions' => $regions,
            'indices' => $indices,
            'index' => $index,
            'followedSectors' => $followedSectors,
            // Drives the "Your Regions" vs "Active Regions" label below — the count alone
            // doesn't tell a new user whether they're looking at their own coverage or the
            // platform-wide fallback, which is exactly what caused the "is this a bug?"
            // confusion between this page and the Dashboard.
            'hasCoverage' => $regionIds !== [] || $subscribedRegionIds !== [],
        ]);
    }

    public function show(Region $region): View
    {
        ['available' => $indices, 'active' => $index] = IndexCoverage::resolve(Auth::user(), request('index'));

        $scores = RegionScore::query()
            ->where('region_id', $region->region_id)
            ->where('index_id', $index->index_id)
            ->orderBy('period_start')
            ->get();

        $latest = $scores->last();
        $prior = $scores->where('period_start', '<=', now()->subDays(14))->last();
        $signalNames = SignalType::codeToName();

        $trend = TrendSummary::describe(
            $latest?->score !== null ? (float) $latest->score : null,
            $prior?->score !== null ? (float) $prior->score : null,
        );

        $diagnosis = ScoreDiagnosis::forBreakdown(
            $latest?->breakdown ?? [],
            $latest?->score !== null ? (float) $latest->score : null,
            $signalNames,
            $trend['direction'],
        );

        // Each driver, plus its plain-language reading — so step 3 shows both the share and
        // "what was actually measured" without the reader jumping back to step 1.
        $rawByCode = collect($latest?->breakdown ?? [])
            ->reject(fn (array $row) => ($row['status'] ?? null) === 'no_data')
            ->keyBy('signal_type_code');
        $drivers = collect($diagnosis['drivers'])->map(function (array $driver) use ($rawByCode) {
            $row = $rawByCode->get($driver['code']);
            $driver['reading'] = $row !== null
                ? SignalReading::describe($driver['code'], (float) $row['raw_value'])['sentence']
                : null;

            return $driver;
        })->all();

        return view('regions.show', [
            'drivers' => $drivers,
            'region' => $region,
            'indices' => $indices,
            'index' => $index,
            'scores' => $scores,
            'latest' => $latest,
            'breakdown' => $latest?->breakdown ?? [],
            'signalNames' => $signalNames,
            'aiAvailable' => app(RegionScoreSummaryService::class)->isAvailable(),
            'recommendedAction' => IndexActionRecommendation::textFor($index->index_id, $latest?->score),
            'diagnosis' => $diagnosis,
            'trend' => $trend,
            'thisWeek' => $this->thisWeekReadings($region, $latest),
            'projection' => $this->projection($latest?->score !== null ? (float) $latest->score : null, $prior?->score !== null ? (float) $prior->score : null),
        ]);
    }

    /**
     * The "This week in {LGA}" list — each signal that fed the score, phrased plainly, most
     * important first, with an "up from / down from recent weeks" clause drawn from the
     * region's own signal history (Clarity Pass A3).
     *
     * @return Collection<int, array{sentence: string}>
     */
    private function thisWeekReadings(Region $region, ?RegionScore $latest): Collection
    {
        if ($latest === null || $latest->score === null) {
            return collect();
        }

        $present = collect($latest->breakdown ?? [])
            ->reject(fn (array $row) => ($row['status'] ?? null) === 'no_data')
            ->sortByDesc(fn (array $row) => $row['contribution_to_final_score'] ?? $row['contribution'] ?? 0)
            ->take(4)
            ->values();

        if ($present->isEmpty()) {
            return collect();
        }

        // The region's own mean for each of these signals over the ~6 periods before this one.
        $codes = $present->pluck('signal_type_code')->all();
        $recentMeans = RegionSignal::query()
            ->join('signal_types', 'signal_types.signal_type_id', '=', 'region_signals.signal_type_id')
            ->where('region_signals.region_id', $region->region_id)
            ->whereIn('signal_types.code', $codes)
            ->where('region_signals.period_start', '<', $latest->period_start->toDateString())
            ->where('region_signals.period_start', '>=', $latest->period_start->copy()->subWeeks(7)->toDateString())
            ->groupBy('signal_types.code')
            ->selectRaw('signal_types.code as code, avg(region_signals.value) as mean, count(*) as n')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->code => $r->n >= 2 ? (float) $r->mean : null]);

        return $present->map(function (array $row) use ($recentMeans) {
            $code = $row['signal_type_code'];
            $value = (float) $row['raw_value'];
            $reading = SignalReading::describe($code, $value);
            $versus = SignalReading::versusRecent($code, $value, $recentMeans[$code] ?? null);

            return ['sentence' => $reading['sentence'].($versus !== '' ? ", {$versus}" : '')];
        });
    }

    /**
     * A plain "if the pattern holds…" line for the "Where it's heading" step. Deliberately
     * conservative: only speaks when the last two readings show a clear move toward a band edge.
     */
    private function projection(?float $latest, ?float $prior): ?string
    {
        if ($latest === null || $prior === null) {
            return null;
        }

        $delta = $latest - $prior;

        if (abs($delta) < 3) {
            return null;
        }

        $projected = $latest + $delta; // one more 2-week step
        $edge = $delta > 0 ? ($latest < 67 ? 67 : null) : ($latest >= 34 ? 34 : null);

        if ($edge === null) {
            return null;
        }

        $crosses = $delta > 0 ? $projected >= $edge : $projected <= $edge;

        if (! $crosses) {
            return null;
        }

        $band = $delta > 0 ? ($edge === 67 ? 'high risk (red)' : 'moderate risk (amber)') : ($edge === 34 ? 'low risk (green)' : 'moderate risk (amber)');

        return "If it keeps moving at this rate it reaches {$band} within about two weeks.";
    }

    public function generateSummary(Region $region, RegionScoreSummaryService $summarizer): RedirectResponse
    {
        ['active' => $index] = IndexCoverage::resolve(Auth::user(), request('index'));

        $latest = RegionScore::query()
            ->where('region_id', $region->region_id)
            ->where('index_id', $index->index_id)
            ->orderByDesc('period_start')
            ->first();

        if ($latest === null || $latest->score === null) {
            return back()->with('error', 'No score to summarize yet for this index.');
        }

        if (! $summarizer->isAvailable()) {
            return back()->with('error', 'AI summaries are not available yet. They need the OpenAI API key to be configured.');
        }

        try {
            $result = $summarizer->generate($latest);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'The AI summary could not be generated right now. Please try again shortly.');
        }

        RegionScore::query()
            ->where('index_id', $latest->index_id)
            ->where('region_id', $latest->region_id)
            ->where('period_start', $latest->period_start)
            ->update([
                'ai_summary' => $result['body'],
                'ai_summary_model' => $result['model'],
                'ai_summary_generated_at' => now(),
            ]);

        return back()->with('success', 'AI summary generated.');
    }
}
