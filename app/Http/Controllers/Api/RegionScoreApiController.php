<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only, token-authenticated API over the same scores the dashboard renders — the
 * developer collateral surface. See docs/INGESTION_GUIDE.md for authentication and endpoint
 * details; a third-party agency plugs into this instead of rebuilding ingestion/scoring.
 */
class RegionScoreApiController extends Controller
{
    public function indices(): JsonResponse
    {
        return response()->json(
            ScoringIndex::all(['index_id', 'code', 'name', 'description'])
        );
    }

    public function regions(): JsonResponse
    {
        return response()->json(
            Region::all(['region_id', 'name', 'state', 'lga_code', 'latitude', 'longitude', 'population'])
        );
    }

    public function latestByIndex(Request $request, string $indexCode): JsonResponse
    {
        $index = ScoringIndex::query()->where('code', strtoupper($indexCode))->firstOrFail();

        $scores = RegionScore::query()
            ->with('region:region_id,name,state,lga_code')
            ->where('index_id', $index->index_id)
            ->when($request->integer('region_id'), fn ($q, $regionId) => $q->where('region_id', $regionId))
            ->orderByDesc('period_start')
            ->get()
            ->unique('region_id')
            ->values();

        return response()->json([
            'index' => $index->only(['index_id', 'code', 'name']),
            'scores' => $scores->map(fn (RegionScore $s) => [
                'region' => $s->region,
                'period_start' => $s->period_start->toDateString(),
                'period_end' => $s->period_end->toDateString(),
                'score' => $s->score,
                'scoring_strategy' => $s->scoring_strategy,
                'breakdown' => $s->breakdown,
            ]),
        ]);
    }

    public function history(Request $request, Region $region): JsonResponse
    {
        $index = ScoringIndex::query()->where('code', strtoupper((string) $request->query('index', 'COMPOSITE_PRESSURE')))->firstOrFail();

        $scores = RegionScore::query()
            ->where('region_id', $region->region_id)
            ->where('index_id', $index->index_id)
            ->orderBy('period_start')
            ->get(['period_start', 'period_end', 'score', 'scoring_strategy']);

        return response()->json([
            'region' => $region->only(['region_id', 'name', 'state']),
            'index' => $index->only(['index_id', 'code', 'name']),
            'scores' => $scores,
        ]);
    }
}
