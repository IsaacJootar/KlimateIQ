<?php

namespace App\Services\Ingestion;

use App\Events\RegionSignalIngested;
use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\SignalType;
use App\Support\Concerns\ResolvesCaBundle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Elevation via Open Topo Data's free SRTM 30m endpoint — no account, no API key.
 *
 * Elevation doesn't change week to week the way rainfall or temperature does, but this
 * still runs on the same weekly cadence as every other source for architectural
 * consistency (one signal_types row, one region_signals row per period, one interface) —
 * refetching an unchanging value is harmless, just slightly redundant.
 */
class ElevationIngestionService implements SignalIngestionService
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://api.opentopodata.org/v1/srtm30m';

    public function signalTypeCode(): string
    {
        return 'ELEVATION';
    }

    public function ingestForRegion(Region $region, Carbon $periodStart, Carbon $periodEnd): ?RegionSignal
    {
        if ($region->latitude === null || $region->longitude === null) {
            return null;
        }

        $response = Http::withOptions(['verify' => $this->caBundle()])
            ->timeout(30)
            ->get(self::BASE_URL, [
                'locations' => "{$region->latitude},{$region->longitude}",
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Open Topo Data request failed with status {$response->status()} for region {$region->region_id}.");
        }

        $elevation = $response->json('results.0.elevation');

        if (! is_numeric($elevation)) {
            return null;
        }

        $signalType = SignalType::query()->where('code', $this->signalTypeCode())->firstOrFail();
        $roundedElevation = round((float) $elevation, 4);

        $signal = RegionSignal::query()->updateOrCreate(
            [
                'region_id' => $region->region_id,
                'signal_type_id' => $signalType->signal_type_id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ],
            [
                'value' => $roundedElevation,
                'raw_metadata' => ['dataset' => $response->json('results.0.dataset')],
                'source' => 'Open Topo Data (SRTM 30m)',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), $roundedElevation);

        return $signal;
    }
}
