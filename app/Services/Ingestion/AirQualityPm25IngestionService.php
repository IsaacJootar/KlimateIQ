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
 * PM2.5 (fine particulate matter) via Open-Meteo's Air Quality API — CAMS (Copernicus
 * Atmosphere Monitoring Service) reanalysis, free tier, no API key. Feeds the Respiratory Risk
 * Index alongside AirQualityPm10IngestionService.
 *
 * No competitor platform we've evaluated covers air quality for Nigeria specifically, and it's
 * a real seasonal hazard here (harmattan dust, urban pollution) — see docs/INGESTION_GUIDE.md.
 *
 * Averaged over the period, not summed — like temperature, a concentration doesn't accumulate.
 * Returns null (not zero) rather than treating an API gap as "clean air."
 */
class AirQualityPm25IngestionService implements SignalIngestionService
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://air-quality-api.open-meteo.com/v1/air-quality';

    public function signalTypeCode(): string
    {
        return 'AIR_QUALITY_PM25';
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
                'hourly' => 'pm2_5',
                'timezone' => 'Africa/Lagos',
            ]);

        if ($response->failed()) {
            return null;
        }

        $hourly = $response->json('hourly.pm2_5', []);
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
