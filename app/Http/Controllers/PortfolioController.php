<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Models\PortfolioLocation;
use App\Models\Region;
use App\Models\User;
use App\Services\Portfolio\PortfolioRollupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Enterprise pivot, Module 2 (docs/ENTERPRISE_BUILD_PLAN.md) — portfolio definition and the
 * rolled-up view. Scoped to the logged-in user's own agency throughout: a portfolio belongs to
 * the agency, any user under that agency can see and manage it (no finer-grained permission yet
 * — matches how saved views / reports are already shared at the agency level, not per-user).
 */
class PortfolioController extends Controller
{
    public function index(): View
    {
        $agencyId = Auth::user()->agency_id;

        return view('portfolios.index', [
            'portfolios' => $agencyId
                ? Portfolio::query()->where('agency_id', $agencyId)->withCount('locations')->orderByDesc('created_at')->get()
                : collect(),
            'hasAgency' => $agencyId !== null,
        ]);
    }

    public function create(): View
    {
        abort_unless(Auth::user()->agency_id, 403, 'Your account isn\'t part of an organization yet.');

        return view('portfolios.create', [
            'agency' => Auth::user()->agency,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()->agency_id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', 'in:point_list,corridor'],
        ]);

        $portfolio = Portfolio::query()->create([
            'agency_id' => Auth::user()->agency_id,
            'name' => $validated['name'],
            'type' => $validated['type'],
        ]);

        return redirect()->route('portfolios.show', $portfolio)->with('status', 'Portfolio created — add locations below.');
    }

    public function show(Portfolio $portfolio, PortfolioRollupService $rollup): View
    {
        $this->authorizeAgency($portfolio);

        $rows = $rollup->rollup($portfolio);

        return view('portfolios.show', [
            'portfolio' => $portfolio,
            'rows' => $rows,
            'mapAssets' => $this->mapAssets($portfolio, $rows),
            'valueSummary' => $rollup->valueSummary($rows),
            'regions' => Region::query()->orderBy('name')->get(['region_id', 'name', 'state']),
            'apiTokens' => $this->tokensForPortfolio($portfolio),
        ]);
    }

    /**
     * Enterprise pivot, Module 12 (docs/ENTERPRISE_BUILD_PLAN.md) — the per-portfolio API token
     * feature (Module 5) was only ever reachable by opening one portfolio and scrolling to the
     * bottom. This gives it its own clearly-labeled home: every portfolio the agency has, each
     * with its own issue/revoke panel, in one place — matching the pitch deck's "Data / API
     * licensing" pillar. No new token logic; same issueToken()/revokeToken() routes per portfolio.
     */
    public function apiAccess(): View
    {
        $agencyId = Auth::user()->agency_id;

        $portfolios = $agencyId
            ? Portfolio::query()->where('agency_id', $agencyId)->orderBy('name')->get()
            : collect();

        return view('portfolios.api-access', [
            'hasAgency' => $agencyId !== null,
            'portfolios' => $portfolios->map(fn (Portfolio $portfolio) => [
                'portfolio' => $portfolio,
                'tokens' => $this->tokensForPortfolio($portfolio),
            ]),
        ]);
    }

    public function destroy(Portfolio $portfolio): RedirectResponse
    {
        $this->authorizeAgency($portfolio);
        $portfolio->delete();

        return redirect()->route('portfolios.index')->with('status', "\"{$portfolio->name}\" deleted.");
    }

    public function addLocation(Request $request, Portfolio $portfolio): RedirectResponse
    {
        $this->authorizeAgency($portfolio);

        $validated = $request->validate([
            'region_id' => ['nullable', 'required_without:latitude', 'exists:regions,region_id'],
            'latitude' => ['nullable', 'required_without:region_id', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'label' => ['nullable', 'string', 'max:200'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'required_with:value', 'in:NGN,USD'],
        ]);

        $nextOrder = $portfolio->isCorridor()
            ? (int) ($portfolio->locations()->max('order') ?? -1) + 1
            : null;

        PortfolioLocation::query()->create([
            'portfolio_id' => $portfolio->portfolio_id,
            'region_id' => $validated['region_id'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'label' => $validated['label'] ?? null,
            'value' => $validated['value'] ?? null,
            'currency' => isset($validated['value']) ? $validated['currency'] : null,
            'order' => $nextOrder,
        ]);

        return back()->with('status', 'Location added.');
    }

    public function removeLocation(Portfolio $portfolio, PortfolioLocation $location): RedirectResponse
    {
        $this->authorizeAgency($portfolio);
        abort_unless($location->portfolio_id === $portfolio->portfolio_id, 404);

        $location->delete();

        return back()->with('status', 'Location removed.');
    }

    /**
     * Enterprise pivot, Module 5 (docs/ENTERPRISE_BUILD_PLAN.md) — Sector #1's "licensed read-only
     * feed." The token is scoped to exactly this Portfolio via a single Sanctum ability, not a
     * new token table — PortfolioFeedApiController checks the same ability string before handing
     * back any data.
     */
    public function issueToken(Request $request, Portfolio $portfolio): RedirectResponse
    {
        $this->authorizeAgency($portfolio);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $token = Auth::user()->createToken($validated['name'], [$portfolio->feedAbility()]);

        return back()
            ->with('status', "Token \"{$validated['name']}\" issued — it can only read this portfolio's feed.")
            ->with('plainTextToken', $token->plainTextToken);
    }

    public function revokeToken(Portfolio $portfolio, PersonalAccessToken $token): RedirectResponse
    {
        $this->authorizeAgency($portfolio);
        abort_unless(in_array($portfolio->feedAbility(), $token->abilities ?? [], true), 404);

        $name = $token->name;
        $token->delete();

        return back()->with('status', "Revoked token \"{$name}\".");
    }

    /**
     * The same window.initRegionMap() JS that draws the all-774-LGA map on /regions, fed only
     * this portfolio's own assets — a coordinate-only location plots at its raw lat/lng (where
     * the asset actually is), a region-backed one at its LGA centroid; a location with neither
     * a region nor a coordinate can't happen (DB constraint), so every row here has a point.
     *
     * @param  Collection<int, array>  $rows  PortfolioRollupService::rollup() output
     * @return Collection<int, array{name: string, latitude: ?float, longitude: ?float, score: ?float, riskBand: string, url: string}>
     */
    private function mapAssets(Portfolio $portfolio, Collection $rows): Collection
    {
        return $rows->map(function (array $row) use ($portfolio) {
            $location = $row['location'];
            $region = $location->region;
            $lat = $region?->latitude ?? $location->latitude;
            $lng = $region?->longitude ?? $location->longitude;

            return [
                'name' => $row['display_name'],
                'latitude' => $lat !== null ? (float) $lat : null,
                'longitude' => $lng !== null ? (float) $lng : null,
                'score' => $row['worst_score'],
                'riskBand' => $row['worst_band'] ?? 'none',
                'url' => $region
                    ? route('regions.show', array_filter(['region' => $region->region_id, 'index' => $row['worst_index_code'], 'portfolio' => $portfolio->portfolio_id]))
                    : route('portfolios.show', $portfolio),
            ];
        })->values();
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    private function tokensForPortfolio(Portfolio $portfolio): Collection
    {
        $agencyUserIds = $portfolio->agency->users()->pluck('id');

        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $agencyUserIds)
            ->with('tokenable')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (PersonalAccessToken $token) => in_array($portfolio->feedAbility(), $token->abilities ?? [], true))
            ->values();
    }

    private function authorizeAgency(Portfolio $portfolio): void
    {
        abort_unless(
            Auth::user()->agency_id !== null && $portfolio->agency_id === Auth::user()->agency_id,
            403,
        );
    }
}
