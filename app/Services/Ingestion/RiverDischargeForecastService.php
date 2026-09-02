<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Services\Ingestion\Concerns\PersistsForecastSeries;
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
    use PersistsForecastSeries;

    public const SIGNAL_CODE = 'RIVER_DISCHARGE';

    public function __construct(private readonly OpenMeteoFloodClient $flood) {}

    public function signalTypeCode(): string
    {
        return self::SIGNAL_CODE;
    }

    public function ingestForecastForRegion(Region $region, Carbon $issuedAt, int $horizonDays): Collection
    {
        $issuedAt = $issuedAt->copy()->startOfDay();
        $series = $this->flood->dailyDischarge($region, $issuedAt, $issuedAt->copy()->addDays($horizonDays));

        if ($series === null) {
            return collect();
        }

        return $this->persistForecastSeries($region, self::SIGNAL_CODE, 'Open-Meteo Flood API (GloFAS)', $issuedAt, $series);
    }
}
