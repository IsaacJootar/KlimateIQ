<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Services\Ingestion\Concerns\PersistsForecastSeries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Daily rainfall forecast (mm) via the Open-Meteo Forecast API — the forward counterpart of
 * RainfallIngestionService, feeding the forward score of every observed index that weights
 * RAINFALL (Flood Risk, Malaria Risk, Drought Risk, Composite). BUILD_PLAN.md T4.
 */
class RainfallForecastService implements ForecastIngestionService
{
    use PersistsForecastSeries;

    public const SIGNAL_CODE = 'RAINFALL';

    public function __construct(private readonly OpenMeteoForecastClient $forecast) {}

    public function signalTypeCode(): string
    {
        return self::SIGNAL_CODE;
    }

    public function ingestForecastForRegion(Region $region, Carbon $issuedAt, int $horizonDays): Collection
    {
        $issuedAt = $issuedAt->copy()->startOfDay();
        $series = $this->forecast->dailySeries($region, $issuedAt, $issuedAt->copy()->addDays($horizonDays), 'precipitation_sum');

        if ($series === null) {
            return collect();
        }

        return $this->persistForecastSeries($region, self::SIGNAL_CODE, 'Open-Meteo Forecast API', $issuedAt, $series);
    }
}
