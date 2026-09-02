<?php

namespace App\Console\Commands;

use App\Models\Region;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Services\Hydrology\ReturnPeriodEstimator;
use App\Services\Ingestion\OpenMeteoFloodClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sets per-LGA RIVER_DISCHARGE bounds for the Riverine Flood Forecast index from ~40 years of
 * GloFAS reanalysis (Open-Meteo Flood API, back to the mid-1980s), so the score means something
 * defensible: a forecast at the 2-year flood level lands around amber, the 20-year level around
 * the top of red.
 *
 *   MIN = the reach's 10th-percentile daily flow (a dry-season low reads green)
 *   MAX = the empirical 20-year return level (annual maxima, Weibull plotting position)
 *
 * The 2-, 5- and 20-year levels are stored in the MAX bound's metadata so the UI can say
 * "forecast to exceed the 5-year flood level". This is NOT a hydrological model calibration
 * (channel geometry, gauge records, a rating curve) — that's a separate, larger exercise — but
 * it is a real return-period estimate, not the "observed max × 1.4" heuristic it replaces.
 *
 * Idempotent. One Flood-API call per reach; run monthly (return periods barely move). Never
 * overwrites a bound an admin set or a real validation produced. `--refresh` recomputes anyway.
 */
class CalibrateRiverDischargeCommand extends Command
{
    protected $signature = 'calibrate:river-discharge
        {--start-year=1985 : First year of GloFAS reanalysis to pull}
        {--min-years=15 : Skip a reach with fewer than this many complete years of record}
        {--region= : Only this region_id}
        {--refresh : Recompute even for reaches already calibrated from reanalysis}';

    protected $description = 'Set per-LGA river-discharge bounds from GloFAS reanalysis return periods.';

    private const SOURCE_PREFIX = 'GloFAS reanalysis (Open-Meteo Flood API)';

    public function handle(OpenMeteoFloodClient $flood, ReturnPeriodEstimator $estimator): int
    {
        $index = ScoringIndex::query()->where('code', 'RIVERINE_FLOOD_FORECAST')->first();
        if ($index === null) {
            $this->warn('Riverine Flood Forecast index not seeded yet.');

            return self::SUCCESS;
        }

        $startYear = (int) $this->option('start-year');
        $minYears = (int) $this->option('min-years');
        $rangeStart = Carbon::create($startYear, 1, 1);
        $rangeEnd = Carbon::now()->startOfYear()->subDay(); // last complete year

        $regions = Region::query()
            ->active()
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->when($this->option('region'), fn ($q) => $q->where('region_id', $this->option('region')))
            ->get();

        $this->info("Calibrating {$regions->count()} reaches from {$startYear}–{$rangeEnd->year} GloFAS reanalysis...");

        $done = 0;
        $skipped = 0;
        $thin = 0;
        $rl20s = [];
        $lows = [];

        foreach ($regions as $region) {
            if (! $this->option('refresh') && $this->alreadyReanalysisCalibrated($index->index_id, $region->region_id)) {
                $skipped++;

                continue;
            }

            $series = $flood->dailyDischarge($region, $rangeStart, $rangeEnd);
            if ($series === null) {
                $thin++;

                continue;
            }

            $annualMaxima = $estimator->annualMaxima($series);
            if (count($annualMaxima) < $minYears) {
                $thin++;

                continue;
            }

            $rl = $estimator->returnLevels($annualMaxima, [2, 5, 20]);
            $low = $estimator->lowFlow($series);
            $years = count($annualMaxima);

            $this->writeBound($index->index_id, $region->region_id, 'MIN', round($low, 2), [
                'method' => 'p10-daily',
                'years_of_record' => $years,
            ], "{$this->sourceRange($startYear, $rangeEnd->year)}: 10th-percentile daily flow.");

            $this->writeBound($index->index_id, $region->region_id, 'MAX', round($rl['20'], 2), [
                'method' => 'weibull-annual-maxima',
                'years_of_record' => $years,
                'return_levels' => ['2' => round($rl['2'], 2), '5' => round($rl['5'], 2), '20' => round($rl['20'], 2)],
            ], "{$this->sourceRange($startYear, $rangeEnd->year)}: empirical 20-year return level (annual maxima, Weibull plotting position).");

            $rl20s[] = $rl['20'];
            $lows[] = $low;
            $done++;

            usleep(300_000); // be gentle with the free API between multi-decade pulls
        }

        // A data-derived system-wide fallback for reaches skipped for a thin record — the median
        // across everything we did calibrate, better than the crude [0, 4000] placeholder.
        if (count($rl20s) >= 3) {
            $this->writeBound($index->index_id, null, 'MAX', round($this->median($rl20s), 2), [
                'method' => 'median-of-per-reach-20-year-levels',
                'reaches' => count($rl20s),
            ], "{$this->sourceRange($startYear, $rangeEnd->year)}: median 20-year return level across {$done} calibrated reaches (system-wide fallback).");
            $this->writeBound($index->index_id, null, 'MIN', round($this->median($lows), 2), [
                'method' => 'median-of-per-reach-low-flows',
                'reaches' => count($lows),
            ], "{$this->sourceRange($startYear, $rangeEnd->year)}: median 10th-percentile flow across calibrated reaches (system-wide fallback).");
        }

        $this->info("Calibrated {$done} reaches, skipped {$skipped} (already done), {$thin} with too little record.");

        return self::SUCCESS;
    }

    private function alreadyReanalysisCalibrated(int $indexId, int $regionId): bool
    {
        return ScoringCalibrationParameter::query()
            ->where('index_id', $indexId)->where('region_id', $regionId)
            ->where('parameter_key', 'RIVER_DISCHARGE_MAX')
            ->where('calibration_status', 'reference_derived')
            ->exists();
    }

    /**
     * @param  array<int, float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function writeBound(int $indexId, ?int $regionId, string $suffix, float $value, array $metadata, string $sourceReference): void
    {
        $existing = ScoringCalibrationParameter::query()
            ->where('index_id', $indexId)
            ->where(fn ($q) => $regionId === null ? $q->whereNull('region_id') : $q->where('region_id', $regionId))
            ->where('parameter_key', "RIVER_DISCHARGE_{$suffix}")
            ->first();

        // Leave a bound a human deliberately set, or a validated one, alone.
        if ($existing !== null && ! $this->isOursToOverwrite($existing)) {
            return;
        }

        ScoringCalibrationParameter::query()->updateOrCreate(
            ['index_id' => $indexId, 'region_id' => $regionId, 'parameter_key' => "RIVER_DISCHARGE_{$suffix}"],
            [
                'parameter_value' => $value,
                'parameter_metadata' => $metadata,
                'source_reference' => $sourceReference,
                'calibration_status' => 'reference_derived',
            ]
        );
    }

    private function isOursToOverwrite(ScoringCalibrationParameter $param): bool
    {
        $ref = (string) $param->source_reference;

        return $ref === ''
            || str_starts_with($ref, self::SOURCE_PREFIX)
            || str_starts_with($ref, 'Uncalibrated placeholder')
            || str_starts_with($ref, "Auto-derived from this LGA's observed discharge"); // the old × 1.4 note
    }

    private function sourceRange(int $from, int $to): string
    {
        return self::SOURCE_PREFIX.", {$from}–{$to}";
    }
}
