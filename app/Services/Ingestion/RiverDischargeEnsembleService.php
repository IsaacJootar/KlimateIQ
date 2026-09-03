<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Services\Ingestion\Concerns\PersistsEnsembleForecastSeries;
use App\Services\Ingestion\Concerns\SamplesRiverReaches;
use Illuminate\Support\Carbon;

/**
 * The GloFAS ensemble river-discharge forecast (BUILD_PLAN.md T5) — ~50 members via the
 * Open-Meteo Flood API (&ensemble=true), the probabilistic counterpart of
 * RiverDischargeForecastService. Feeds the distribution of the Riverine Flood Forecast index.
 */
class RiverDischargeEnsembleService implements EnsembleForecastIngestionService
{
    use PersistsEnsembleForecastSeries, SamplesRiverReaches;

    public const SIGNAL_CODE = 'RIVER_DISCHARGE';

    public function __construct(private readonly OpenMeteoFloodClient $flood) {}

    public function signalTypeCode(): string
    {
        return self::SIGNAL_CODE;
    }

    public function ingestEnsembleForRegion(Region $region, Carbon $issuedAt, int $horizonDays): int
    {
        $issuedAt = $issuedAt->copy()->startOfDay();
        $end = $issuedAt->copy()->addDays($horizonDays);
        $written = 0;

        foreach ($this->reachesFor($region) as $reach) {
            $members = $this->flood->ensembleDailyDischarge($region, $issuedAt, $end, $reach['lat'], $reach['lon']);
            if ($members === null || $members === []) {
                continue;
            }

            $source = 'Open-Meteo Flood API (GloFAS ensemble)'.($reach['river'] ? " — {$reach['river']}" : '');
            $written += $this->persistEnsembleSeries($region, self::SIGNAL_CODE, $source, $issuedAt, $members, $reach['reach']);
        }

        return $written;
    }
}
