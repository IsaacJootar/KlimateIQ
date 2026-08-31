<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\RegionScore;
use App\Models\Sector;
use App\Support\RiskBand;
use App\Support\TrendSummary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Clarity Pass B3 — the sector home. One screen that answers "what is my situation in this
 * sector this week": every index in it as a status card, and one headline count of the LGAs
 * that need attention across the whole sector. Drilling into a card goes to the full index
 * view on the Regions page.
 */
class SectorController extends Controller
{
    /** score at or above which an LGA "needs attention" — amber and red (see App\Support\RiskBand). */
    private const ATTENTION = 34;

    public function show(Sector $sector): View
    {
        $user = Auth::user();
        $sector->load('indices');

        // Same region scoping as the dashboard: the user's own regions, or every active one.
        $regionIds = $user->regionSubscriptions()->pluck('region_id');
        $regions = Region::query()
            ->when($regionIds->isNotEmpty(),
                fn ($q) => $q->whereIn('region_id', $regionIds),
                fn ($q) => $q->active())
            ->get()->keyBy('region_id');

        $indexIds = $sector->indices->pluck('index_id')->all();

        $latest = $this->latestScoresByIndex($indexIds, $regions->keys()->all(), null);
        $prior = $this->latestScoresByIndex($indexIds, $regions->keys()->all(), now()->subDays(14)->toDateString());

        $cards = $sector->indices->map(function ($index) use ($latest, $prior, $regions) {
            $scored = ($latest[$index->index_id] ?? collect())->filter(fn ($s) => $s->score !== null);
            $needAttention = $scored->filter(fn ($s) => (float) $s->score >= self::ATTENTION);
            $worst = $scored->sortByDesc(fn ($s) => (float) $s->score)->first();

            $avgNow = $scored->avg(fn ($s) => (float) $s->score);
            $priorScored = ($prior[$index->index_id] ?? collect())->filter(fn ($s) => $s->score !== null);
            $avgThen = $priorScored->isNotEmpty() ? $priorScored->avg(fn ($s) => (float) $s->score) : null;

            return [
                'index' => $index,
                'scored_count' => $scored->count(),
                'need_attention' => $needAttention->count(),
                'band' => $worst !== null ? RiskBand::forScore((float) $worst->score) : 'none',
                'worst_region' => $worst !== null ? ($regions[$worst->region_id]->name ?? null) : null,
                'worst_score' => $worst !== null ? rtrim(rtrim(number_format((float) $worst->score, 1), '0'), '.') : null,
                'trend' => TrendSummary::describe($avgNow, $avgThen),
            ];
        });

        // Sector headline — distinct LGAs that need attention on *any* index in the sector.
        $regionsNeedingAttention = $latest
            ->flatMap(fn ($scores) => $scores->filter(fn ($s) => $s->score !== null && (float) $s->score >= self::ATTENTION)->pluck('region_id'))
            ->unique();

        return view('sectors.show', [
            'sector' => $sector,
            'cards' => $cards,
            'followed' => $user->sectorSubscriptions()->where('sector_id', $sector->sector_id)->exists(),
            'regionCount' => $regions->count(),
            'attentionCount' => $regionsNeedingAttention->count(),
            'hasCoverage' => $regionIds->isNotEmpty(),
        ]);
    }

    /**
     * The most recent score per region for each of the given indices — one grouped collection,
     * keyed by index_id. $onOrBefore narrows to "the score as of N days ago" for a trend.
     *
     * @param  array<int>  $indexIds
     * @param  array<int>  $regionIds
     * @return Collection<int, Collection<int, RegionScore>>
     */
    private function latestScoresByIndex(array $indexIds, array $regionIds, ?string $onOrBefore): Collection
    {
        if ($indexIds === [] || $regionIds === []) {
            return collect();
        }

        return RegionScore::query()
            ->whereIn('index_id', $indexIds)
            ->whereIn('region_id', $regionIds)
            ->when($onOrBefore !== null, fn ($q) => $q->where('period_start', '<=', $onOrBefore))
            ->orderByDesc('period_start')
            ->get(['index_id', 'region_id', 'period_start', 'score'])
            ->groupBy('index_id')
            ->map(fn ($group) => $group->unique('region_id')->values());
    }
}
