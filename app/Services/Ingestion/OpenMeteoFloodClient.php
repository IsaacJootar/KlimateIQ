<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Support\Concerns\ResolvesCaBundle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Open-Meteo's Flood API — GloFAS (Global Flood Awareness System) river discharge, modelled
 * on a ~5 km river network. Free tier, no API key, no account (docs/INGESTION_GUIDE.md). Used
 * for both lanes: `past_days` for the observed weekly reading, `forecast_days` for the daily
 * forward series. Discharge is a rate (m³/s), not an accumulation.
 *
 * GloFAS only models reaches on a mapped river network — an LGA whose centre point sits away
 * from a modelled reach returns nulls, which both callers treat as "no coverage here" (null),
 * not "no flood risk" (zero).
 */
class OpenMeteoFloodClient
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://flood-api.open-meteo.com/v1/flood';

    private const VARIABLE = 'river_discharge';

    /**
     * The daily river-discharge series for a region over a date range. Returns a date => m³/s
     * map (only the days the API actually reported a numeric value), or null when the region
     * has no coordinates or the request failed or the reach is unmodelled.
     *
     * @return array<string, float>|null
     */
    public function dailyDischarge(Region $region, Carbon $start, Carbon $end): ?array
    {
        if ($region->latitude === null || $region->longitude === null) {
            return null;
        }

        // A multi-decade daily series (calibrate:river-discharge pulls ~40 years) is a big
        // response — well past the 30s the short forecast pull needs.
        $timeout = $start->diffInYears($end) >= 5 ? 90 : 30;

        $response = Http::withOptions(['verify' => $this->caBundle()])
            ->timeout($timeout)
            ->retry(2, 2000, throw: false)
            ->get(self::BASE_URL, [
                'latitude' => (float) $region->latitude,
                'longitude' => (float) $region->longitude,
                'daily' => self::VARIABLE,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'timezone' => 'Africa/Lagos',
            ]);

        if ($response->failed()) {
            return null;
        }

        $dates = $response->json('daily.time', []);
        $values = $response->json('daily.'.self::VARIABLE, []);

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
