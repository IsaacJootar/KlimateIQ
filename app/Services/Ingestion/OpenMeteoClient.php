<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Support\Concerns\ResolvesCaBundle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Open-Meteo's historical archive API (ERA5-backed), used as a fallback when NASA POWER is
 * unavailable — not a replacement for it. Deliberately scoped to general climate signals only
 * (rainfall, temperature); standing water, population, and elevation stay on their purpose-built
 * sources. Free tier, no API key, no account — see docs/INGESTION_GUIDE.md.
 */
class OpenMeteoClient
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://archive-api.open-meteo.com/v1/archive';

    /**
     * @return array<int, float>|null null when the region has no coordinates or the request fails
     */
    public function fetchDaily(Region $region, Carbon $periodStart, Carbon $periodEnd, string $variable): ?array
    {
        if ($region->latitude === null || $region->longitude === null) {
            return null;
        }

        $response = Http::withOptions(['verify' => $this->caBundle()])
            ->timeout(30)
            ->get(self::BASE_URL, [
                'latitude' => (float) $region->latitude,
                'longitude' => (float) $region->longitude,
                'start_date' => $periodStart->toDateString(),
                'end_date' => $periodEnd->toDateString(),
                'daily' => $variable,
                'timezone' => 'Africa/Lagos',
            ]);

        if ($response->failed()) {
            return null;
        }

        $daily = $response->json("daily.{$variable}", []);
        $validReadings = array_values(array_filter($daily, fn ($v) => is_numeric($v)));

        return $validReadings === [] ? null : $validReadings;
    }
}
