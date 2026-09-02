<?php

namespace App\Console\Commands;

use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\SignalType;
use App\Services\Ingestion\OpenMeteoFloodClient;
use App\Support\IngestionWindow;
use Illuminate\Console\Command;

/**
 * Front-loads a year of weekly RIVER_DISCHARGE history per LGA so calibrate:river-discharge has
 * a real distribution to work from (BUILD_PLAN.md T4). Without it, the weekly calibration run
 * no-ops until ~4 weeks of live ingestion have accrued, and even then it has only seen one
 * season's flow — a forecast index calibrated on dry-season discharge would never fire.
 *
 * GloFAS reanalysis reaches back to 1984 through the Flood API's date-range mode, so one call
 * per region covers the whole window. Bucketed into the same 7-day periods and weekly-mean
 * semantics RiverDischargeIngestionService uses live. Never overwrites a real reading, and does
 * not dispatch RegionSignalIngested — this is historical context for calibration, not a live
 * reading that should retroactively trip an alert.
 */
class BackfillRiverDischargeCommand extends Command
{
    protected $signature = 'signals:backfill-discharge
        {--weeks=52 : How many prior 7-day periods to backfill, stepping back from just before the live ingestion window}
        {--region= : Only this region_id}';

    protected $description = 'Backfill weekly river-discharge history via the Open-Meteo Flood API so per-LGA calibration has a full seasonal record.';

    public function handle(OpenMeteoFloodClient $flood): int
    {
        $weeks = max(1, (int) $this->option('weeks'));

        $signalTypeId = SignalType::query()->where('code', 'RIVER_DISCHARGE')->value('signal_type_id');
        if ($signalTypeId === null) {
            $this->warn('RIVER_DISCHARGE signal type not seeded yet — run AdditionalIndicesSeeder first.');

            return self::SUCCESS;
        }

        $regions = Region::query()
            ->active()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($this->option('region'), fn ($q) => $q->where('region_id', $this->option('region')))
            ->get();

        // Step back from just before the live window so a backfill can't collide with what a
        // scheduled ingestion just wrote.
        [$liveWindowStart] = IngestionWindow::lastComplete();
        $latestPeriodEnd = $liveWindowStart->copy()->subDay();
        $rangeStart = $latestPeriodEnd->copy()->subDays($weeks * 7 - 1);

        $this->info("Backfilling up to {$weeks} weeks of discharge for {$regions->count()} active regions...");

        $created = 0;
        $skipped = 0;
        $noCoverage = 0;

        foreach ($regions as $region) {
            $series = $flood->dailyDischarge($region, $rangeStart, $latestPeriodEnd);

            if ($series === null) {
                $noCoverage++;

                continue;
            }

            $periodEnd = $latestPeriodEnd->copy();

            for ($i = 0; $i < $weeks; $i++) {
                $periodStart = $periodEnd->copy()->subDays(6);

                $daily = [];
                for ($d = $periodStart->copy(); $d->lte($periodEnd); $d->addDay()) {
                    $key = $d->toDateString();
                    if (isset($series[$key])) {
                        $daily[$key] = $series[$key];
                    }
                }

                $periodEndForNext = $periodStart->copy()->subDay();

                // Need at least four of the seven days to call it a weekly mean.
                if (count($daily) >= 4) {
                    $exists = RegionSignal::query()
                        ->where('region_id', $region->region_id)
                        ->where('signal_type_id', $signalTypeId)
                        ->where('period_start', $periodStart->toDateString())
                        ->where('period_end', $periodEnd->toDateString())
                        ->exists();

                    if ($exists) {
                        $skipped++;
                    } else {
                        RegionSignal::query()->create([
                            'region_id' => $region->region_id,
                            'signal_type_id' => $signalTypeId,
                            'period_start' => $periodStart->toDateString(),
                            'period_end' => $periodEnd->toDateString(),
                            'value' => round(array_sum($daily) / count($daily), 4),
                            'raw_metadata' => ['daily_m3s' => $daily, 'days_reported' => count($daily), 'backfill' => true],
                            'source' => 'Open-Meteo Flood API (GloFAS reanalysis backfill)',
                            'ingested_at' => now(),
                        ]);
                        $created++;
                    }
                }

                $periodEnd = $periodEndForNext;
            }
        }

        $this->info("Done. Created {$created} weekly discharge signals, skipped {$skipped} (already had data), {$noCoverage} regions off the modelled river network.");

        return self::SUCCESS;
    }
}
