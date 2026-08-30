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
 * Root-zone soil moisture via Open-Meteo's historical archive (ERA5-Land), the 7–28 cm layer —
 * the band that matters for rain-fed crops and the earliest pre-visible signal of agricultural
 * water stress, weeks before NDVI shows anything. Free tier, no API key — see
 * docs/INGESTION_GUIDE.md.
 *
 * Hourly readings (m³/m³, volumetric water content) averaged over the period — a moisture
 * fraction doesn't accumulate, like temperature and air quality. Returns null (not zero) on an
 * API gap rather than reporting bone-dry soil that wasn't measured.
 *
 * Direction is inverse: drier soil is worse. signal_types.higher_is_worse is false for this
 * signal, and every index that uses it (Agriculture Stress, Irrigation Need) treats low as bad.
 */
class SoilMoistureIngestionService implements SignalIngestionService
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://archive-api.open-meteo.com/v1/archive';

    private const LAYER = 'soil_moisture_7_to_28cm';

    public function signalTypeCode(): string
    {
        return 'SOIL_MOISTURE';
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
                'hourly' => self::LAYER,
                'timezone' => 'Africa/Lagos',
            ]);

        if ($response->failed()) {
            return null;
        }

        $hourly = $response->json('hourly.'.self::LAYER, []);
        $validReadings = array_values(array_filter($hourly, fn ($v) => is_numeric($v)));

        if ($validReadings === []) {
            return null;
        }

        $average = round(array_sum($validReadings) / count($validReadings), 4);
        $signalType = SignalType::query()->where('code', $this->signalTypeCode())->firstOrFail();

        $signal = RegionSignal::query()->updateOrCreate(
            [
                'region_id' => $region->region_id,
                'signal_type_id' => $signalType->signal_type_id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ],
            [
                'value' => $average,
                'raw_metadata' => ['hourly_m3_m3' => $validReadings, 'hours_reported' => count($validReadings), 'layer' => self::LAYER],
                'source' => 'Open-Meteo Archive API (ERA5-Land, 7–28 cm)',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), $average);

        return $signal;
    }
}
