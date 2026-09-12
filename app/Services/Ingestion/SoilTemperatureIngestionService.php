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
 * Surface soil temperature (0–7 cm) via Open-Meteo's historical archive (ERA5-Land) — the
 * layer that matters for seed germination and early seedling survival, alongside soil moisture
 * and rainfall onset. Feeds the Planting Window Risk index. Free tier, no API key.
 *
 * Hourly readings (°C) averaged over the period, like soil moisture — a temperature doesn't
 * accumulate. Returns null (not zero) on an API gap.
 */
class SoilTemperatureIngestionService implements SignalIngestionService
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://archive-api.open-meteo.com/v1/archive';

    private const LAYER = 'soil_temperature_0_to_7cm';

    public function signalTypeCode(): string
    {
        return 'SOIL_TEMPERATURE';
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
                'raw_metadata' => ['hourly_c' => $validReadings, 'hours_reported' => count($validReadings), 'layer' => self::LAYER],
                'source' => 'Open-Meteo Archive API (ERA5-Land, 0–7 cm)',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), $average);

        return $signal;
    }
}
