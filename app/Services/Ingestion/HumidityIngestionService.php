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
 * Near-surface relative humidity via Open-Meteo's historical archive (ERA5). Dry air is the
 * hazard in the use cases we score for — it drives bush-fire spread, harmattan dust lofting,
 * and dry-season meningitis transmission — so signal_types.higher_is_worse is false (low is
 * bad) and those indices use the bare direction. A future heat-index index would override it.
 *
 * Hourly percentages averaged over the period; a fraction doesn't accumulate. Returns null
 * (not zero) on an API gap.
 */
class HumidityIngestionService implements SignalIngestionService
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://archive-api.open-meteo.com/v1/archive';

    private const VARIABLE = 'relative_humidity_2m';

    public function signalTypeCode(): string
    {
        return 'HUMIDITY';
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
                'hourly' => self::VARIABLE,
                'timezone' => 'Africa/Lagos',
            ]);

        if ($response->failed()) {
            return null;
        }

        $hourly = $response->json('hourly.'.self::VARIABLE, []);
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
                'raw_metadata' => ['hourly_pct' => $validReadings, 'hours_reported' => count($validReadings)],
                'source' => 'Open-Meteo Archive API (ERA5, relative humidity 2 m)',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), $average);

        return $signal;
    }
}
