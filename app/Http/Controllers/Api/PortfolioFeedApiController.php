<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Portfolio;
use App\Services\Portfolio\PortfolioRollupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Enterprise pivot, Module 5 (docs/ENTERPRISE_BUILD_PLAN.md) — Sector #1, Parametric Insurance &
 * Reinsurance: "a licensed read-only feed... for a named set of LGAs, with a signed data-licensing
 * agreement an insurer's payout contract can reference" (ENTERPRISE_PIVOT_PLAN.md §5). No new
 * scoring — this is PortfolioRollupService's existing output, reshaped into a stable JSON
 * contract and gated by a token scoped to exactly one Portfolio (see
 * PortfolioController::issueToken()), so a client's integration can only ever read the data they
 * licensed, not every agency's portfolio.
 */
class PortfolioFeedApiController extends Controller
{
    public function show(Request $request, Portfolio $portfolio, PortfolioRollupService $rollup): JsonResponse
    {
        // Sanctum's guard checks the web session before the bearer token, and a session hit
        // authenticates with a TransientToken whose can() always returns true (laravel/sanctum
        // Guard::__invoke) — fine for the platform's other read endpoints, which return nothing
        // more sensitive than what the dashboard already shows, but not here: this feed exists
        // specifically to gate one agency's portfolio from another's. A real scoped
        // PersonalAccessToken is required; a logged-in browser session is not enough.
        abort_unless($request->user()?->currentAccessToken() instanceof PersonalAccessToken, 403, 'This endpoint requires a token scoped to this portfolio, not a browser session.');
        abort_unless($request->user()->tokenCan($portfolio->feedAbility()), 403, 'This token is not licensed for this portfolio.');

        // Defense in depth: if the token holder has since left the agency that owns this
        // portfolio, the token itself is still valid elsewhere but no longer speaks for this one.
        abort_unless($request->user()->agency_id === $portfolio->agency_id, 403, 'This token is not licensed for this portfolio.');

        $rows = $rollup->rollup($portfolio);

        return response()->json([
            'portfolio' => $portfolio->name,
            'generated_at' => now(),
            'locations' => $rows->map(fn (array $row) => [
                'name' => $row['display_name'],
                'scorable' => $row['scorable'],
                'approximated' => $row['approximated'],
                'nearest_region_name' => $row['nearest_region_name'],
                'distance_km' => $row['distance_km'],
                'reason' => $row['reason'],
                'scores' => $row['scores']->map(fn (array $s) => [
                    'score' => $s['score'],
                    'band' => $s['band'],
                    'as_of' => $s['as_of'],
                    'is_forecast' => $s['is_forecast'],
                    'lead_days' => $s['lead_days'],
                    'target_date' => $s['target_date'],
                ]),
                'worst_index_code' => $row['worst_index_code'],
                'worst_index_name' => $row['worst_index_name'],
                'worst_score' => $row['worst_score'],
                'worst_band' => $row['worst_band'],
            ])->values(),
        ]);
    }
}
