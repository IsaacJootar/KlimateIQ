<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Region;
use App\Models\RegionScore;
use App\Models\RegionSignal;
use App\Models\ScoringIndex;
use App\Models\ThresholdConfig;
use App\Support\RiskBand;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();

        $regionIds = $user->regionSubscriptions()->pluck('region_id');
        $indexIds = $user->indexSubscriptions()->pluck('index_id');

        // "All regions" (no explicit coverage) means "everything currently active," not
        // literally all 774 seeded LGAs — most of which nobody has ever asked about.
        $regions = Region::query()
            ->when(
                $regionIds->isNotEmpty(),
                fn ($q) => $q->whereIn('region_id', $regionIds),
                fn ($q) => $q->active()
            )
            ->get();

        $subscribedIndices = ScoringIndex::query()->whereIn('index_id', $indexIds)->get();

        // Tabs: the indices this user actually picked in Coverage, so they can switch between
        // them instead of the Dashboard silently choosing just one. No index preference set at
        // all means "show everything," matching how an empty region selection already behaves.
        $availableIndices = $subscribedIndices->isNotEmpty()
            ? $subscribedIndices->sortBy('name')->values()
            : ScoringIndex::query()->orderBy('name')->get();

        // An explicit ?index= (clicking a tab) wins if it's one of this user's available
        // indices. Otherwise, the same deliberate default as before: this used to be
        // ScoringIndex::find($indexIds->first()), which just took whichever subscribed index
        // happened to have the lowest index_id — in practice always Malaria Risk (index_id 1)
        // whenever it was among the selection, regardless of what else was picked, making
        // "change your coverage" look like it did nothing. Composite Climate-Health Pressure —
        // the one index designed to be "the overall snapshot" — is the more defensible default
        // than an arbitrary ID when several are selected with no explicit "primary" to ask for;
        // otherwise fall back to alphabetical, at least predictable instead of coincidental.
        $activeIndex = $availableIndices->firstWhere('code', request('index'))
            ?? match (true) {
                $subscribedIndices->isEmpty() => ScoringIndex::where('code', 'COMPOSITE_PRESSURE')->first(),
                $subscribedIndices->count() === 1 => $subscribedIndices->first(),
                default => $subscribedIndices->firstWhere('code', 'COMPOSITE_PRESSURE')
                    ?? $subscribedIndices->sortBy('name')->first(),
            };

        $latestScores = RegionScore::query()
            ->where('index_id', $activeIndex->index_id)
            ->whereIn('region_id', $regions->pluck('region_id'))
            ->orderByDesc('period_start')
            ->get()
            ->unique('region_id');

        $highRiskCount = $latestScores->where('score', '>=', 67)->count();

        // "Where do I act first" — every scored region ranked, highest risk first, so a
        // coordinator managing several LGAs doesn't have to eyeball the full regions table.
        $topRiskRegions = $latestScores
            ->whereNotNull('score')
            ->sortByDesc('score')
            ->take(5)
            ->map(fn (RegionScore $score) => [
                'region' => $regions->firstWhere('region_id', $score->region_id),
                'score' => $score->score,
                'band' => RiskBand::forScore($score->score),
            ])
            ->filter(fn (array $row) => $row['region'] !== null)
            ->values();

        $openAlertsCount = Alert::query()
            ->whereHas('thresholdConfig', fn ($q) => $q->where('user_id', $user->id))
            ->where('status', 'OPEN')
            ->count();

        $activeThresholdsCount = ThresholdConfig::query()->where('user_id', $user->id)->where('active', true)->count();

        $recentAlerts = Alert::query()
            ->whereHas('thresholdConfig', fn ($q) => $q->where('user_id', $user->id))
            ->with(['region', 'index', 'signalType'])
            ->orderByDesc('triggered_at')
            ->limit(5)
            ->get();

        // A lightweight "what's the pipeline doing" feed — recent ingestion and scoring runs,
        // not scoped to just this user's coverage, since it's meant to show the platform is alive.
        $recentSignals = RegionSignal::query()
            ->with(['region', 'signalType'])
            ->orderByDesc('ingested_at')
            ->limit(5)
            ->get()
            ->map(fn (RegionSignal $s) => [
                'label' => "{$s->signalType->name} ingested for {$s->region->name}",
                'value' => "{$s->value} {$s->signalType->unit}",
                'at' => $s->ingested_at,
            ]);

        $recentCalculations = RegionScore::query()
            ->with(['region', 'index'])
            ->orderByDesc('calculated_at')
            ->limit(5)
            ->get()
            ->map(fn (RegionScore $s) => [
                'label' => "{$s->index->name} calculated for {$s->region->name}",
                'value' => $s->score !== null ? "score {$s->score}" : 'no data',
                'at' => $s->calculated_at,
            ]);

        $activityFeed = $recentSignals->concat($recentCalculations)
            ->sortByDesc('at')
            ->take(8)
            ->values();

        return view('dashboard', [
            'hasCoverage' => $regionIds->isNotEmpty(),
            'regionsCount' => $regions->count(),
            'highRiskCount' => $highRiskCount,
            'openAlertsCount' => $openAlertsCount,
            'activeThresholdsCount' => $activeThresholdsCount,
            'defaultIndex' => $activeIndex,
            'availableIndices' => $availableIndices,
            'topRiskRegions' => $topRiskRegions,
            'recentAlerts' => $recentAlerts,
            'activityFeed' => $activityFeed,
        ]);
    }
}
