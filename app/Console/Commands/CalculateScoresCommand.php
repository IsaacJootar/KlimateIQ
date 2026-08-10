<?php

namespace App\Console\Commands;

use App\Models\Region;
use App\Models\ScoringIndex;
use App\Services\Scoring\RegionScoringService;
use App\Support\IngestionWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CalculateScoresCommand extends Command
{
    protected $signature = 'scores:calculate
        {--region= : Only score this region_id}
        {--index= : Only score this index code, e.g. MALARIA_RISK}
        {--period-start= : Recalculate this specific period instead of the current one (YYYY-MM-DD)}
        {--period-end= : Required alongside --period-start (YYYY-MM-DD)}';

    protected $description = 'Calculate every named index for every region over the last complete signal week.';

    public function handle(RegionScoringService $service): int
    {
        if ($this->option('period-start') xor $this->option('period-end')) {
            $this->error('--period-start and --period-end must be given together.');

            return self::FAILURE;
        }

        // Bug found and fixed here: this used to compute its own Carbon::now()->subDays(6)
        // window inline, duplicating IngestionWindow::lastComplete() rather than sharing it.
        // The two happened to agree whenever both ran the same day, but a job queued on one
        // day and only processed (via a delayed queue worker) a day or two later carries its
        // *dispatch-time* window baked in — so signals could land for a period that no longer
        // matches what a same-day scores:calculate run computes fresh, producing real ingested
        // signals with no score ever calculated for their actual period. --period-start/-end
        // exists specifically to let a stale backlog be scored for the period it actually has
        // data for, rather than "whatever period is current right now."
        [$periodStart, $periodEnd] = $this->option('period-start')
            ? [Carbon::parse($this->option('period-start')), Carbon::parse($this->option('period-end'))]
            : IngestionWindow::lastComplete();

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
