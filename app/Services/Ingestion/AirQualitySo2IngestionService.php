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
 * Sulphur dioxide via Open-Meteo's Air Quality API (CAMS) — a marker of diesel generator and
 * industrial exhaust, and a bronchoconstriction trigger even at brief high concentrations.
 * Feeds the Respiratory Risk Index alongside the PM, ozone, NO₂, dust and CO series; see
 * AirQualityPm25IngestionService for the shared source rationale.
 *
 * Hourly µg/m³ averaged over the period. Returns null (not zero) on an API gap.
 */
class AirQualitySo2IngestionService implements SignalIngestionService
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://air-quality-api.open-meteo.com/v1/air-quality';

    public function signalTypeCode(): string
    {
        return 'SO2';
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
                'hourly' => 'sulphur_dioxide',
                'timezone' => 'Africa/Lagos',
            ]);

        if ($response->failed()) {
            return null;
        }

        $hourly = $response->json('hourly.sulphur_dioxide', []);
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
                'raw_metadata' => ['hourly_ug_m3' => $validReadings, 'hours_reported' => count($validReadings)],
                'source' => 'Open-Meteo Air Quality API (CAMS)',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), $average);

        return $signal;
    }
}
