<?php

namespace App\Console\Commands;

use App\Models\Region;
use App\Models\RiverReach;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Services\Hydrology\ReturnPeriodEstimator;
use App\Services\Ingestion\OpenMeteoFloodClient;
use App\Support\CalibrationStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sets per-LGA RIVER_DISCHARGE bounds for the Riverine Flood Forecast index from ~25 years of
 * GloFAS reanalysis (Open-Meteo Flood API — Nigerian reaches begin ~1997), so the score means
 * something defensible: a forecast at the 2-year flood level lands around amber, the 20-year
 * level around the top of red.
 *
 *   MIN = the reach's 10th-percentile daily flow (a dry-season low reads green)
 *   MAX = the empirical 20-year return level (annual maxima, Weibull plotting position)
 *
 * The 2-, 5- and 20-year levels are stored in the MAX bound's metadata so the UI can say
 * "forecast to exceed the 5-year flood level". This is NOT a hydrological model calibration
 * (channel geometry, gauge records, a rating curve) — that's a separate, larger exercise — but
 * it is a real return-period estimate, not the "observed max × 1.4" heuristic it replaces.
 *
 * The history is pulled in ~8-year chunks (a multi-decade request over 30+ reaches is slow and
 * flaky). Idempotent, runs monthly (return periods barely move), skips already-done reaches.
 * Never overwrites a bound an admin set or a real validation produced. `--refresh` recomputes.
 *
 * T4/T5 follow-up: a multi-river LGA (Lokoja, Bassa) has curated `river_reaches` rows — the
 * command calibrates each reach point separately and writes bounds tagged with that reach. An
 * LGA with no reaches calibrates once at its centroid, `reach = null` (the LGA-wide bound).
 */
class CalibrateRiverDischargeCommand extends Command
{
    protected $signature = 'calibrate:river-discharge
        {--start-year=1998 : First year of GloFAS reanalysis to pull}
        {--min-years=10 : Skip a reach with fewer than this many complete years of record}
        {--region= : Only this region_id}
        {--reach= : Only this reach slug (with --region)}
        {--refresh : Recompute even for reaches already calibrated from reanalysis}';

    protected $description = 'Set per-reach river-discharge bounds from GloFAS reanalysis return periods.';

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

        // (region, reach) pairs: a multi-river LGA expands to one job per reach; others get one
        // job at the centroid (reach = null → the LGA-wide bound, unchanged).
        $jobs = $regions->flatMap(function (Region $region) {
            $reaches = RiverReach::query()->where('region_id', $region->region_id)
                ->when($this->option('reach'), fn ($q) => $q->where('reach', $this->option('reach')))
                ->get();

            return $reaches->isEmpty()
                ? [['region' => $region, 'reach' => null, 'river' => null, 'lat' => null, 'lon' => null]]
                : $reaches->map(fn (RiverReach $r) => [
                    'region' => $region, 'reach' => $r->reach, 'river' => $r->river,
                    'lat' => $r->latitude, 'lon' => $r->longitude,
                ])->all();
        });

        $this->info("Calibrating {$jobs->count()} reach(es) from {$startYear}–{$rangeEnd->year} GloFAS reanalysis...");

        $done = 0;
        $skipped = 0;
        $thin = 0;

        foreach ($jobs as $job) {
            /** @var Region $region */
            $region = $job['region'];
            $reach = $job['reach'];

            if (! $this->option('refresh') && $this->alreadyReanalysisCalibrated($index->index_id, $region->region_id, $reach)) {
                $skipped++;

                continue;
            }

            $series = $this->pullInChunks($flood, $region, $rangeStart, $rangeEnd, $job['lat'], $job['lon']);
            if ($series === null || count($estimator->annualMaxima($series)) < $minYears) {
                $thin++;
                $this->dropStalePerRegionBounds($index->index_id, $region->region_id, $reach);

                continue;
            }

            $annualMaxima = $estimator->annualMaxima($series);

            $rl = $estimator->returnLevels($annualMaxima, [2, 5, 20]);
            $low = $estimator->lowFlow($series);
            $years = count($annualMaxima);
            $riverNote = $job['river'] ? " ({$job['river']})" : '';

            $this->writeBound($index->index_id, $region->region_id, $reach, 'MIN', round($low, 2), [
                'method' => 'p10-daily',
                'years_of_record' => $years,
            ], "{$this->sourceRange($startYear, $rangeEnd->year)}{$riverNote}: 10th-percentile daily flow.");

            $this->writeBound($index->index_id, $region->region_id, $reach, 'MAX', round($rl['20'], 2), [
                'method' => 'weibull-annual-maxima',
                'years_of_record' => $years,
                'return_levels' => ['2' => round($rl['2'], 2), '5' => round($rl['5'], 2), '20' => round($rl['20'], 2)],
            ], "{$this->sourceRange($startYear, $rangeEnd->year)}{$riverNote}: empirical 20-year return level (annual maxima, Weibull plotting position).");

            $done++;

            usleep(300_000); // be gentle with the free API between multi-decade pulls
        }

        // No system-wide fallback bound. Rivers span three orders of magnitude of flow — a
        // single shared MAX is either far too low (any real river pegs at 100 — the failure
        // this platform actually hit) or far too high (every flood reads as normal). A reach we
        // can't calibrate individually is left with *no* per-region bound, and the forecast
        // scorer refuses to score it (shows "calibration pending") rather than emit a number
        // that isn't grounded in that reach's own hydrology.

        $this->info("Calibrated {$done} reaches, skipped {$skipped} (already done), {$thin} with too little record.");

        return self::SUCCESS;
    }

    /**
     * A multi-decade daily series in one request is slow and flaky over 30+ reaches; pull it in
     * ~8-year windows and merge. Any window coming back null means the reach isn't modelled that
     * far back — keep what we got and stop.
     *
     * @return array<string, float>|null
     */
    private function pullInChunks(OpenMeteoFloodClient $flood, Region $region, Carbon $rangeStart, Carbon $rangeEnd, ?float $lat = null, ?float $lon = null): ?array
    {
        $merged = [];
        $windowStart = $rangeStart->copy();

        while ($windowStart->lte($rangeEnd)) {
            $windowEnd = min($windowStart->copy()->addYears(8)->subDay(), $rangeEnd);
            $chunk = $flood->dailyDischarge($region, $windowStart, $windowEnd, $lat, $lon);

            if ($chunk === null) {
                break;
            }

            $merged += $chunk;
            $windowStart = $windowEnd->copy()->addDay();
            usleep(200_000);
        }

        return $merged === [] ? null : $merged;
    }

    /**
     * A reach we can't get enough reanalysis for is left with no bound — the forecast scorer
     * then shows "calibration pending" rather than a borrowed number. Drop a stale bound we
     * wrote before; leave a hand-set or already-reanalysis-derived one alone.
     */
    private function dropStalePerRegionBounds(int $indexId, int $regionId, ?string $reach): void
    {
        ScoringCalibrationParameter::query()
            ->where('index_id', $indexId)->where('region_id', $regionId)
            ->where(fn ($q) => $reach === null ? $q->whereNull('reach') : $q->where('reach', $reach))
            ->whereIn('parameter_key', ['RIVER_DISCHARGE_MIN', 'RIVER_DISCHARGE_MAX'])
            ->get()
            ->each(function (ScoringCalibrationParameter $param) {
                if ($this->isOursToOverwrite($param) && $param->calibration_status !== CalibrationStatus::ReferenceDerived) {
                    $param->delete();
                }
            });
    }

    private function alreadyReanalysisCalibrated(int $indexId, int $regionId, ?string $reach): bool
    {
        return ScoringCalibrationParameter::query()
            ->where('index_id', $indexId)->where('region_id', $regionId)
            ->where(fn ($q) => $reach === null ? $q->whereNull('reach') : $q->where('reach', $reach))
            ->where('parameter_key', 'RIVER_DISCHARGE_MAX')
            ->where('calibration_status', 'reference_derived')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function writeBound(int $indexId, int $regionId, ?string $reach, string $suffix, float $value, array $metadata, string $sourceReference): void
    {
        $existing = ScoringCalibrationParameter::query()
            ->where('index_id', $indexId)
            ->where('region_id', $regionId)
            ->where(fn ($q) => $reach === null ? $q->whereNull('reach') : $q->where('reach', $reach))
            ->where('parameter_key', "RIVER_DISCHARGE_{$suffix}")
            ->first();

        // Leave a bound a human deliberately set, or a validated one, alone.
        if ($existing !== null && ! $this->isOursToOverwrite($existing)) {
            return;
        }

        ScoringCalibrationParameter::query()->updateOrCreate(
            ['index_id' => $indexId, 'region_id' => $regionId, 'reach' => $reach, 'parameter_key' => "RIVER_DISCHARGE_{$suffix}"],
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
