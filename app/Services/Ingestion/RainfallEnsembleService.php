<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Services\Ingestion\Concerns\PersistsEnsembleForecastSeries;
use App\Services\Ingestion\Concerns\PoolsWeatherEnsemble;
use Illuminate\Support\Carbon;

/**
 * Pooled multi-model rainfall ensemble (mm/day) via the Open-Meteo Ensemble API — the
 * probabilistic counterpart of RainfallForecastService (BUILD_PLAN.md T5). Feeds the
 * distribution of every observed index that weights RAINFALL (Flood Risk, Malaria, Drought,
 * Composite).
 */
class RainfallEnsembleService implements EnsembleForecastIngestionService
{
    use PersistsEnsembleForecastSeries, PoolsWeatherEnsemble;

    public const SIGNAL_CODE = 'RAINFALL';

    public function __construct(private readonly OpenMeteoEnsembleClient $ensemble) {}

    public function signalTypeCode(): string
    {
        return self::SIGNAL_CODE;
    }

    public function ingestEnsembleForRegion(Region $region, Carbon $issuedAt, int $horizonDays): int
    {
        $issuedAt = $issuedAt->copy()->startOfDay();
        $members = $this->pooledWeatherMembers(
            $this->ensemble, $region, $issuedAt, $issuedAt->copy()->addDays($horizonDays), 'precipitation_sum',
        );

        if ($members === []) {
            return 0;
        }

        return $this->persistEnsembleSeries($region, self::SIGNAL_CODE, 'Open-Meteo Ensemble API (multi-model)', $issuedAt, $members);
    }
}
