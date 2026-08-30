<?php

namespace App\Http\Controllers;

use App\Models\IndexActionRecommendation;
use App\Models\Region;
use App\Models\RegionScore;
use App\Models\SignalType;
use App\Services\Ai\RegionScoreSummaryService;
use App\Support\IndexCoverage;
use App\Support\RiskBand;
use App\Support\ScoreDiagnosis;
use App\Support\TrendSummary;
use Illuminate\Http\RedirectResponse;
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
        $diagnosis = ScoreDiagnosis::forBreakdown(
            $latest?->breakdown ?? [],
            $latest?->score !== null ? (float) $latest->score : null,
            $signalNames,
        );

        return view('regions.show', [
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
            'trend' => TrendSummary::describe(
                $latest?->score !== null ? (float) $latest->score : null,
                $prior?->score !== null ? (float) $prior->score : null,
            ),
        ]);
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
