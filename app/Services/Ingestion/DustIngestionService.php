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
 * Mineral dust concentration via Open-Meteo's Air Quality API (CAMS) — the direct measure of a
 * harmattan dust event, distinct from PM10 (which mixes dust with other coarse particulates).
 * Feeds Dust Storm Risk and, later, a deeper Respiratory Risk and a Sahel meningitis index.
 *
 * Hourly µg/m³ averaged over the period, like the PM services. Returns null (not zero) on a gap.
 */
class DustIngestionService implements SignalIngestionService
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://air-quality-api.open-meteo.com/v1/air-quality';

    public function signalTypeCode(): string
    {
        return 'DUST';
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
                'hourly' => 'dust',
                'timezone' => 'Africa/Lagos',
            ]);

        if ($response->failed()) {
            return null;
        }

        $hourly = $response->json('hourly.dust', []);
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
                'source' => 'Open-Meteo Air Quality API (CAMS dust)',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), $average);

        return $signal;
    }
}
