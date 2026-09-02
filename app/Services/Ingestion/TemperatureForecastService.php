<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Services\Ingestion\Concerns\PersistsForecastSeries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Daily mean-temperature forecast (°C) via the Open-Meteo Forecast API — the forward
 * counterpart of TemperatureIngestionService, feeding the forward score of every observed
 * index that weights TEMPERATURE (Heat Stress Risk, Composite). BUILD_PLAN.md T4.
 */
class TemperatureForecastService implements ForecastIngestionService
{
    use PersistsForecastSeries;

    public const SIGNAL_CODE = 'TEMPERATURE';

    public function __construct(private readonly OpenMeteoForecastClient $forecast) {}

    public function signalTypeCode(): string
    {
        return self::SIGNAL_CODE;
    }

    public function ingestForecastForRegion(Region $region, Carbon $issuedAt, int $horizonDays): Collection
    {
        $issuedAt = $issuedAt->copy()->startOfDay();
        $series = $this->forecast->dailySeries($region, $issuedAt, $issuedAt->copy()->addDays($horizonDays), 'temperature_2m_mean');

        if ($series === null) {
            return collect();
        }

        return $this->persistForecastSeries($region, self::SIGNAL_CODE, 'Open-Meteo Forecast API', $issuedAt, $series);
    }
}
