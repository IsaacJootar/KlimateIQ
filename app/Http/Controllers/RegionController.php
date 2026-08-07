<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use App\Services\Ai\RegionScoreSummaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegionController extends Controller
{
    public function index(): View
    {
        $indices = ScoringIndex::all();
        $index = $indices->firstWhere('code', request('index', 'COMPOSITE_PRESSURE')) ?? $indices->first();

        $latestByRegion = RegionScore::query()
            ->where('index_id', $index->index_id)
            ->orderByDesc('period_start')
            ->get()
            ->unique('region_id')
            ->keyBy('region_id');

        $regionIds = array_filter(explode(',', (string) request('regions', '')));

        $regions = Region::query()
            ->when($regionIds !== [], fn ($q) => $q->whereIn('region_id', $regionIds))
            ->orderBy('name')
            ->get()
            ->map(function (Region $region) use ($latestByRegion) {
                $score = $latestByRegion->get($region->region_id);
                $region->setAttribute('current_score', $score?->score);
                $region->setAttribute('risk_band', $this->riskBand($score?->score));

                return $region;
            });

        return view('regions.index', [
            'regions' => $regions,
            'indices' => $indices,
            'index' => $index,
        ]);
    }

    public function show(Region $region): View
    {
        $indices = ScoringIndex::all();
        $index = $indices->firstWhere('code', request('index', 'COMPOSITE_PRESSURE')) ?? $indices->first();

        $scores = RegionScore::query()
            ->where('region_id', $region->region_id)
            ->where('index_id', $index->index_id)
            ->orderBy('period_start')
            ->get();

        $latest = $scores->last();

        return view('regions.show', [
            'region' => $region,
            'indices' => $indices,
            'index' => $index,
            'scores' => $scores,
            'latest' => $latest,
            'breakdown' => $latest?->breakdown ?? [],
            'aiAvailable' => app(RegionScoreSummaryService::class)->isAvailable(),
        ]);
    }

    public function generateSummary(Region $region, RegionScoreSummaryService $summarizer): RedirectResponse
    {
        $index = ScoringIndex::all()->firstWhere('code', request('index', 'COMPOSITE_PRESSURE'));

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

    private function riskBand(?float $score): string
    {
        return match (true) {
            $score === null => 'none',
            $score < 34 => 'green',
            $score < 67 => 'amber',
            default => 'red',
        };
    }
}
