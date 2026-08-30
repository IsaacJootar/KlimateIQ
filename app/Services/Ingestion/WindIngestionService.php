<?php

namespace App\Services\Ingestion;

use App\Events\RegionSignalIngested;
use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\SignalType;
use App\Support\Concerns\ResolvesCaBundle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * 10 m wind speed via Open-Meteo's historical archive (ERA5). What matters for fire spread and
 * dust transport is how strong the wind gets, not its round-the-clock average — so this takes
 * the daily maximum and averages those daily peaks over the period. km/h. Higher is worse.
 *
 * Returns null (not zero) on an API gap.
 */
class WindIngestionService implements SignalIngestionService
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://archive-api.open-meteo.com/v1/archive';

    private const VARIABLE = 'wind_speed_10m_max';

    public function signalTypeCode(): string
    {
        return 'WIND_SPEED';
    }

    public function ingestForRegion(Region $region, Carbon $periodStart, Carbon $periodEnd): ?RegionSignal
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
                'daily' => self::VARIABLE,
                'timezone' => 'Africa/Lagos',
            ]);

        if ($response->failed()) {
            return null;
        }

        $daily = $response->json('daily.'.self::VARIABLE, []);
        $validReadings = array_values(array_filter($daily, fn ($v) => is_numeric($v)));

        if ($validReadings === []) {
            return null;
        }

        $averagePeak = round(array_sum($validReadings) / count($validReadings), 4);
        $signalType = SignalType::query()->where('code', $this->signalTypeCode())->firstOrFail();

        $signal = RegionSignal::query()->updateOrCreate(
            [
                'region_id' => $region->region_id,
                'signal_type_id' => $signalType->signal_type_id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ],
            [
                'value' => $averagePeak,
                'raw_metadata' => ['daily_max_kmh' => $validReadings, 'days_reported' => count($validReadings)],
                'source' => 'Open-Meteo Archive API (ERA5, 10 m wind daily max)',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), $averagePeak);

        return $signal;
    }
}
