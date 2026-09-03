<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Services\Ingestion\Concerns\PersistsEnsembleForecastSeries;
use App\Services\Ingestion\Concerns\PoolsWeatherEnsemble;
use Illuminate\Support\Carbon;

/**
 * Pooled multi-model mean-temperature ensemble (°C/day) via the Open-Meteo Ensemble API — the
 * probabilistic counterpart of TemperatureForecastService (BUILD_PLAN.md T5). Feeds the
 * distribution of Heat Stress and Composite.
 */
class TemperatureEnsembleService implements EnsembleForecastIngestionService
{
    use PersistsEnsembleForecastSeries, PoolsWeatherEnsemble;

    public const SIGNAL_CODE = 'TEMPERATURE';

    public function __construct(private readonly OpenMeteoEnsembleClient $ensemble) {}

    public function signalTypeCode(): string
    {
        return self::SIGNAL_CODE;
    }

    public function ingestEnsembleForRegion(Region $region, Carbon $issuedAt, int $horizonDays): int
    {
        $issuedAt = $issuedAt->copy()->startOfDay();
        $members = $this->pooledWeatherMembers(
            $this->ensemble, $region, $issuedAt, $issuedAt->copy()->addDays($horizonDays), 'temperature_2m_mean',
        );

        if ($members === []) {
            return 0;
        }

        return $this->persistEnsembleSeries($region, self::SIGNAL_CODE, 'Open-Meteo Ensemble API (multi-model)', $issuedAt, $members);
    }
}
