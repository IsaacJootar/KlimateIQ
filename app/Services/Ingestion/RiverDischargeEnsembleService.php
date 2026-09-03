<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Services\Ingestion\Concerns\PersistsEnsembleForecastSeries;
use Illuminate\Support\Carbon;

/**
 * The GloFAS ensemble river-discharge forecast (BUILD_PLAN.md T5) — ~50 members via the
 * Open-Meteo Flood API (&ensemble=true), the probabilistic counterpart of
 * RiverDischargeForecastService. Feeds the distribution of the Riverine Flood Forecast index.
 */
class RiverDischargeEnsembleService implements EnsembleForecastIngestionService
{
    use PersistsEnsembleForecastSeries;

    public const SIGNAL_CODE = 'RIVER_DISCHARGE';

    public function __construct(private readonly OpenMeteoFloodClient $flood) {}

    public function signalTypeCode(): string
    {
        return self::SIGNAL_CODE;
    }

    public function ingestEnsembleForRegion(Region $region, Carbon $issuedAt, int $horizonDays): int
    {
        $issuedAt = $issuedAt->copy()->startOfDay();
        $members = $this->flood->ensembleDailyDischarge($region, $issuedAt, $issuedAt->copy()->addDays($horizonDays));

        if ($members === null || $members === []) {
            return 0;
        }

        return $this->persistEnsembleSeries($region, self::SIGNAL_CODE, 'Open-Meteo Flood API (GloFAS ensemble)', $issuedAt, $members);
    }
}
