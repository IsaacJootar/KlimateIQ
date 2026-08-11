<?php

namespace App\Console\Commands;

use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\SignalType;
use App\Services\Ingestion\OpenMeteoClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deepens each active region's anomaly-detection baseline using Open-Meteo's ERA5-backed
 * historical archive — ThresholdEvaluationService's rolling mean/stddev looks at the 8 most
 * recent prior region_signals rows (see ANOMALY_WINDOW), and with ingestion only running for a
 * few weeks so far, that window is mostly empty. This backfills real historical weekly periods
 * so anomaly detection has an actual statistical basis instead of 1-2 data points.
 *
 * Deliberately only backfills RAINFALL and TEMPERATURE (Open-Meteo's scope — see
 * docs/INGESTION_GUIDE.md's resilience section) and only for periods that don't already have a
 * real signal — this never overwrites a live NASA POWER (or Open-Meteo fallback) reading.
 *
 * Does not fire RegionSignalIngested — this is historical context for future evaluations, not a
 * live reading that should retroactively trigger a threshold alert about something that happened
 * weeks ago.
 */
class BackfillHistoricalSignalsCommand extends Command
{
    protected $signature = 'signals:backfill-history
        {--periods=12 : How many prior 7-day periods to backfill, stepping back from just before the live ingestion window}';

    protected $description = 'Backfill historical Rainfall/Temperature signals via Open-Meteo ERA5 to deepen anomaly-detection baselines';

    public function handle(OpenMeteoClient $openMeteo): int
    {
        $periodCount = (int) $this->option('periods');
        $regions = Region::query()->active()->whereNotNull('latitude')->whereNotNull('longitude')->get();
        $signalTypes = SignalType::query()->whereIn('code', ['RAINFALL', 'TEMPERATURE'])->get()->keyBy('code');

        // Step back from just before the live ingestion window, so a backfill run can never
        // collide with the period a real scheduled ingestion just wrote.
        [$liveWindowStart] = \App\Support\IngestionWindow::lastComplete();
        $periodEnd = $liveWindowStart->copy()->subDay();

        $this->info("Backfilling {$periodCount} periods for {$regions->count()} active regions...");

        $created = 0;
        $skipped = 0;
        $failed = 0;

        for ($i = 0; $i < $periodCount; $i++) {
            $periodStart = $periodEnd->copy()->subDays(6);

            foreach ($regions as $region) {
                foreach (['RAINFALL' => 'precipitation_sum', 'TEMPERATURE' => 'temperature_2m_mean'] as $code => $variable) {
                    $signalType = $signalTypes->get($code);

                    if (! $signalType) {
                        continue;
                    }

                    $exists = RegionSignal::query()
                        ->where('region_id', $region->region_id)
                        ->where('signal_type_id', $signalType->signal_type_id)
                        ->where('period_start', $periodStart->toDateString())
                        ->where('period_end', $periodEnd->toDateString())
                        ->exists();

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    $daily = $openMeteo->fetchDaily($region, $periodStart, $periodEnd, $variable);

                    if ($daily === null) {
                        $failed++;

                        continue;
                    }

                    $value = $code === 'RAINFALL'
                        ? round(array_sum($daily), 4)
                        : round(array_sum($daily) / count($daily), 4);

                    RegionSignal::query()->create([
                        'region_id' => $region->region_id,
                        'signal_type_id' => $signalType->signal_type_id,
                        'period_start' => $periodStart->toDateString(),
                        'period_end' => $periodEnd->toDateString(),
                        'value' => $value,
                        'raw_metadata' => ['daily' => $daily, 'days_reported' => count($daily), 'backfill' => true],
                        'source' => 'Open-Meteo (ERA5 historical backfill)',
                        'ingested_at' => now(),
                    ]);

                    $created++;
                }
            }

            $periodEnd = $periodStart->copy()->subDay();
        }

        $this->info("Done. Created {$created} signals, skipped {$skipped} (already had real data), {$failed} had no data available.");

        return self::SUCCESS;
    }
}
