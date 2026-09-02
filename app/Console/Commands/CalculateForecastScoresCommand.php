<?php

namespace App\Console\Commands;

use App\Models\Region;
use App\Models\ScoringIndex;
use App\Services\Scoring\RegionForecastScoringService;
use Illuminate\Console\Command;

/**
 * The forecast counterpart of scores:calculate (BUILD_PLAN.md T4). Scores every forecast index —
 * the dedicated ones (Riverine Flood Forecast) and every observed index that weights a
 * forecastable signal (Flood Risk, Heat Stress, …) — for every active region, from the forecast
 * signals currently on file, as of today. Writes region_forecast_scores; never touches
 * region_scores.
 */
class CalculateForecastScoresCommand extends Command
{
    protected $signature = 'scores:forecast
        {--region= : Only score this region_id}
        {--index= : Only score this index code, e.g. RIVERINE_FLOOD_FORECAST}';

    protected $description = 'Calculate every forecast index for every active region from the forecast signals on file.';

    public function handle(RegionForecastScoringService $service): int
    {
        $regions = $this->option('region')
            ? Region::query()->where('region_id', $this->option('region'))->get()
            : Region::query()->active()->get();

        $forecastSignalCodes = collect(config('ingestion.forecast_sources', []))
            ->map(fn ($class) => app($class)->signalTypeCode())
            ->all();

        $indices = $this->option('index')
            ? ScoringIndex::query()->where('code', $this->option('index'))->get()
            : ScoringIndex::query()->forwardScorable($forecastSignalCodes)->get();

        $scored = 0;

        foreach ($indices as $index) {
            foreach ($regions as $region) {
                $result = $service->calculate($index, $region);
                $this->line($result->score !== null
                    ? "  {$index->code} — {$region->name}: {$result->score} (peak +{$result->leadDaysToPeak}d)"
                    : "  {$index->code} — {$region->name}: no forecast coverage");
                $scored++;
            }
        }

        $this->info("Ran {$scored} forecast index/region scorings.");

        return self::SUCCESS;
    }
}
