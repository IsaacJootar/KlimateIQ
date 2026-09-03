<?php

namespace App\Services\Ingestion\Concerns;

use App\Models\Region;
use App\Services\Ingestion\OpenMeteoEnsembleClient;
use Illuminate\Support\Carbon;

/**
 * Shared by the weather ensemble services (BUILD_PLAN.md T5): pull one daily variable from every
 * configured weather model (config('ingestion.ensemble.weather_models')) and pool the members
 * into one `memberId => (date => value)` map — gfs-01…, ecmwf-01…, icon-01…. A model that
 * returns nothing is simply absent from the pool.
 */
trait PoolsWeatherEnsemble
{
    /**
     * @return array<string, array<string, float>>
     */
    protected function pooledWeatherMembers(OpenMeteoEnsembleClient $client, Region $region, Carbon $start, Carbon $end, string $variable): array
    {
        $pool = [];

        foreach (config('ingestion.ensemble.weather_models', []) as $model) {
            $pool += $client->memberSeries($region, $start, $end, $variable, $model);
        }

        return $pool;
    }
}
