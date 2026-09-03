<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Services\Ingestion\Concerns\PersistsForecastSeries;
use App\Services\Ingestion\Concerns\SamplesRiverReaches;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * River-discharge forecast (m³/s) via the Open-Meteo Flood API / GloFAS — the forecast lane
 * (BUILD_PLAN.md T4). Pulls the daily forward series from the issue date out to the horizon.
 *
 * v1 keeps only the latest issuance, so each run replaces this region+signal's whole forecast
 * series in one transaction — that also prunes target dates that have dropped out of the window
 * (yesterday's "+14 days" is today's "+13"). Issuance history is T5.
 *
 * The normal-flow reference each day's value gets scored against lives in scoring, not here
 * (scoring_calibration_parameters RIVER_DISCHARGE_MIN/MAX, per-region overridable).
 */
class RiverDischargeForecastService implements ForecastIngestionService
{
    use PersistsForecastSeries, SamplesRiverReaches;

    public const SIGNAL_CODE = 'RIVER_DISCHARGE';

    public function __construct(private readonly OpenMeteoFloodClient $flood) {}

    public function signalTypeCode(): string
    {
        return self::SIGNAL_CODE;
    }

    public function ingestForecastForRegion(Region $region, Carbon $issuedAt, int $horizonDays): Collection
    {
        $issuedAt = $issuedAt->copy()->startOfDay();
        $end = $issuedAt->copy()->addDays($horizonDays);
        $written = collect();

        foreach ($this->reachesFor($region) as $reach) {
            $series = $this->flood->dailyDischarge($region, $issuedAt, $end, $reach['lat'], $reach['lon']);
            if ($series === null) {
                continue;
            }

            $source = 'Open-Meteo Flood API (GloFAS)'.($reach['river'] ? " — {$reach['river']}" : '');
            $written = $written->concat(
                $this->persistForecastSeries($region, self::SIGNAL_CODE, $source, $issuedAt, $series, $reach['reach']),
            );
        }

        $this->pruneCentroidRows($region, self::SIGNAL_CODE);

        return $written;
    }
}
