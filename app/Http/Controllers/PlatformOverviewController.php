<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Alert;
use App\Models\Region;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use App\Models\UserRegionSubscription;
use App\Support\RiskBand;
use Illuminate\View\View;

/**
 * The one page that deliberately ignores "just show me my own coverage" — built for an
 * organization like NCDC whose whole job is seeing across every agency, not just its own
 * configured regions. Gated by EnsureFederalOversight, which checks the viewing user's
 * agency.federal_oversight flag rather than any per-user grant.
 */
class PlatformOverviewController extends Controller
{
    public function index(): View
    {
        $defaultIndex = ScoringIndex::where('code', 'COMPOSITE_PRESSURE')->firstOrFail();

        $activeRegions = Region::query()->active()->get();

        $latestScores = RegionScore::query()
            ->where('index_id', $defaultIndex->index_id)
            ->whereIn('region_id', $activeRegions->pluck('region_id'))
            ->orderByDesc('period_start')
            ->get()
            ->unique('region_id');

        $topRiskRegions = $latestScores
            ->whereNotNull('score')
            ->sortByDesc('score')
            ->take(10)
            ->map(fn (RegionScore $score) => [
                'region' => $activeRegions->firstWhere('region_id', $score->region_id),
                'score' => $score->score,
                'band' => RiskBand::forScore($score->score),
            ])
            ->filter(fn (array $row) => $row['region'] !== null)
            ->values();

        // agency_id is denormalized onto threshold_configs specifically so alert counts like
        // this don't need a join through users — see AgencyManagementController::merge().
        $agencyBreakdown = Agency::query()
            ->withCount('users')
            ->get()
            ->map(function (Agency $agency) {
                $userIds = $agency->users()->pluck('id');

                return [
                    'agency' => $agency,
                    'user_count' => $agency->users_count,
                    'regions_watched' => UserRegionSubscription::query()
                        ->whereIn('user_id', $userIds)
                        ->distinct('region_id')
                        ->count('region_id'),
                    'open_alerts' => Alert::query()
                        ->whereHas('thresholdConfig', fn ($q) => $q->where('agency_id', $agency->agency_id))
                        ->where('status', 'OPEN')
                        ->count(),
                ];
            })
            ->sortByDesc('open_alerts')
            ->values();

        return view('overview.index', [
            'defaultIndex' => $defaultIndex,
            'activeRegionsCount' => $activeRegions->count(),
            'openAlertsCount' => Alert::query()->where('status', 'OPEN')->count(),
            'agencyCount' => Agency::query()->count(),
            'topRiskRegions' => $topRiskRegions,
            'agencyBreakdown' => $agencyBreakdown,
        ]);
    }
}
