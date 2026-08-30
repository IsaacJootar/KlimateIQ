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
 * Reference evapotranspiration (ET₀, FAO-56 Penman-Monteith) via Open-Meteo's historical
 * archive — how much water the atmosphere pulled out of a reference crop over the period.
 * Paired with rainfall and soil moisture it's the demand side of the crop water balance:
 * high ET₀ against low rainfall is drought stress building before it's visible. Free tier,
 * no API key — see docs/INGESTION_GUIDE.md.
 *
 * Daily millimetres summed over the period — water lost accumulates, like rainfall. Higher is
 * worse (more crop water demand). Returns null (not zero) on an API gap.
 */
class EvapotranspirationIngestionService implements SignalIngestionService
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://archive-api.open-meteo.com/v1/archive';

    private const VARIABLE = 'et0_fao_evapotranspiration';

    public function signalTypeCode(): string
    {
        return 'EVAPOTRANSPIRATION';
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

        $totalMm = round(array_sum($validReadings), 4);
        $signalType = SignalType::query()->where('code', $this->signalTypeCode())->firstOrFail();

        $signal = RegionSignal::query()->updateOrCreate(
            [
                'region_id' => $region->region_id,
                'signal_type_id' => $signalType->signal_type_id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ],
            [
                'value' => $totalMm,
                'raw_metadata' => ['daily_mm' => $validReadings, 'days_reported' => count($validReadings)],
                'source' => 'Open-Meteo Archive API (FAO-56 ET₀)',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), $totalMm);

        return $signal;
    }
}
