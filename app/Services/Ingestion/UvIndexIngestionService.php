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
 * UV index via Open-Meteo's historical archive (ERA5) — the daily peak, not a round-the-clock
 * average, same reasoning as WindIndexIngestionService: what matters for occupational / skin-eye
 * exposure advisories is how strong the midday UV gets. Feeds a deepened Heat Stress Risk
 * alongside temperature and vegetation.
 *
 * Averages the daily maxima over the period. Returns null (not zero) on an API gap.
 */
class UvIndexIngestionService implements SignalIngestionService
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://archive-api.open-meteo.com/v1/archive';

    private const VARIABLE = 'uv_index_max';

    public function signalTypeCode(): string
    {
        return 'UV_INDEX';
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
                'raw_metadata' => ['daily_max_uv_index' => $validReadings, 'days_reported' => count($validReadings)],
                'source' => 'Open-Meteo Archive API (ERA5, daily UV index max)',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), $averagePeak);

        return $signal;
    }
}
