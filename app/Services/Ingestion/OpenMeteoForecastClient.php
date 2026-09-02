<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Support\Concerns\ResolvesCaBundle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Open-Meteo's Forecast API (ICON / GFS / ECMWF blend) — the forward counterpart of
 * OpenMeteoClient's ERA5 archive. Used to forward-score the observed indices whose signals
 * have a real forecast (rainfall, temperature): BUILD_PLAN.md T4's "config add on this lane".
 * Free tier, no API key, no account.
 *
 * One daily variable per call, mirroring OpenMeteoClient::fetchDaily — cheap enough at
 * LGA granularity (a couple of calls per active region per day) and keeps each forecast
 * source a thin one-signal class.
 */
class OpenMeteoForecastClient
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://api.open-meteo.com/v1/forecast';

    /**
     * The daily forecast series for one variable over a date range. Returns a date => value
     * map (only days the API reported a numeric value), or null when the region has no
     * coordinates or the request failed.
     *
     * @return array<string, float>|null
     */
    public function dailySeries(Region $region, Carbon $start, Carbon $end, string $variable): ?array
    {
        if ($region->latitude === null || $region->longitude === null) {
            return null;
        }

        $response = Http::withOptions(['verify' => $this->caBundle()])
            ->timeout(30)
            ->get(self::BASE_URL, [
                'latitude' => (float) $region->latitude,
                'longitude' => (float) $region->longitude,
                'daily' => $variable,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'timezone' => 'Africa/Lagos',
            ]);

        if ($response->failed()) {
            return null;
        }

        $dates = $response->json('daily.time', []);
        $values = $response->json("daily.{$variable}", []);

        $series = [];
        foreach ($dates as $i => $date) {
            $value = $values[$i] ?? null;
            if (is_numeric($value)) {
                $series[$date] = (float) $value;
            }
        }

        return $series === [] ? null : $series;
    }
}
