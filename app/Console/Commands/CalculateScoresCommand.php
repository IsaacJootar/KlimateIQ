<?php

namespace App\Console\Commands;

use App\Models\Region;
use App\Models\ScoringIndex;
use App\Services\Scoring\RegionScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CalculateScoresCommand extends Command
{
    protected $signature = 'scores:calculate
        {--region= : Only score this region_id}
        {--index= : Only score this index code, e.g. MALARIA_RISK}';

    protected $description = 'Calculate every named index for every region over the last complete signal week.';

    public function handle(RegionScoringService $service): int
    {
        $periodEnd = Carbon::now()->subDays(6)->startOfDay();
        $periodStart = $periodEnd->copy()->subDays(6);

        $regions = $this->option('region')
            ? Region::query()->where('region_id', $this->option('region'))->get()
            : Region::query()->get();

        $indices = $this->option('index')
            ? ScoringIndex::query()->where('code', $this->option('index'))->get()
            : ScoringIndex::query()->get();

        $calculated = 0;

        foreach ($indices as $index) {
            foreach ($regions as $region) {
                $result = $service->calculate($index, $region, $periodStart, $periodEnd);
                $this->line("  {$index->code} — {$region->name}: ".($result->score !== null ? "{$result->score}" : 'no data'));
                $calculated++;
            }
        }

        $this->info("Calculated {$calculated} index/region scores for {$periodStart->toDateString()}..{$periodEnd->toDateString()}.");

        return self::SUCCESS;
    }
}
