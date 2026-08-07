<?php

namespace App\Services\Ingestion;

use App\Events\RegionSignalIngested;
use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\SignalType;
use App\Services\Ingestion\Concerns\ResolvesCaBundle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Rainfall via the NASA POWER daily point API.
 *
 * The brief names CHIRPS or GPM IMERG, both distributed as raster/NetCDF grids that need
 * geospatial extraction, and GPM IMERG additionally requires a NASA Earthdata login. NASA
 * POWER serves the same class of satellite/reanalysis-derived precipitation as simple
 * per-point JSON with no account or API key, so it proves the ingestion pipeline today.
 * Swapping in CHIRPS/GPM IMERG later means writing a new class against this same interface —
 * nothing else in the system changes. See the developer guide for that swap.
 */
class RainfallIngestionService implements SignalIngestionService
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://power.larc.nasa.gov/api/temporal/daily/point';

    private const FILL_VALUE = -999.0;

    public function signalTypeCode(): string
    {
        return 'RAINFALL';
    }

    public function ingestForRegion(Region $region, Carbon $periodStart, Carbon $periodEnd): ?RegionSignal
    {
        if ($region->latitude === null || $region->longitude === null) {
            return null;
        }

        $response = Http::withOptions(['verify' => $this->caBundle()])
            ->timeout(30)
            ->get(self::BASE_URL, [
                'parameters' => 'PRECTOTCORR',
                'community' => 'AG',
                'longitude' => (float) $region->longitude,
                'latitude' => (float) $region->latitude,
                'start' => $periodStart->format('Ymd'),
                'end' => $periodEnd->format('Ymd'),
                'format' => 'JSON',
            ]);

        if ($response->failed()) {
            throw new RuntimeException("NASA POWER request failed with status {$response->status()} for region {$region->region_id}.");
        }

        $daily = $response->json('properties.parameter.PRECTOTCORR', []);

        $validReadings = array_filter(
            $daily,
            fn ($mm) => is_numeric($mm) && (float) $mm !== self::FILL_VALUE
        );

        if ($validReadings === []) {
            return null;
        }

        $totalMm = array_sum($validReadings);
        $signalType = SignalType::query()->where('code', $this->signalTypeCode())->firstOrFail();
        $roundedTotal = round($totalMm, 4);

        $signal = RegionSignal::query()->updateOrCreate(
            [
                'region_id' => $region->region_id,
                'signal_type_id' => $signalType->signal_type_id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ],
            [
                'value' => $roundedTotal,
                'raw_metadata' => ['daily_mm' => $validReadings, 'days_reported' => count($validReadings)],
                'source' => 'NASA POWER (PRECTOTCORR)',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), $roundedTotal);

        return $signal;
    }
}
