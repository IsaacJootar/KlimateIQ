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
 * Temperature via the NASA POWER daily point API — the same zero-account endpoint already
 * proven by RainfallIngestionService, just a different parameter (T2M instead of PRECTOTCORR).
 * The signal_types seed data already named "NASA POWER / ERA5" as this signal's source; POWER
 * covers it without ERA5's Copernicus CDS account being needed at all.
 */
class TemperatureIngestionService implements SignalIngestionService
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://power.larc.nasa.gov/api/temporal/daily/point';

    private const FILL_VALUE = -999.0;

    public function __construct(private readonly OpenMeteoClient $openMeteo) {}

    public function signalTypeCode(): string
    {
        return 'TEMPERATURE';
    }

    public function ingestForRegion(Region $region, Carbon $periodStart, Carbon $periodEnd): ?RegionSignal
    {
        if ($region->latitude === null || $region->longitude === null) {
            return null;
        }

        $response = Http::withOptions(['verify' => $this->caBundle()])
            ->timeout(30)
            ->get(self::BASE_URL, [
                'parameters' => 'T2M',
                'community' => 'AG',
                'longitude' => (float) $region->longitude,
                'latitude' => (float) $region->latitude,
                'start' => $periodStart->format('Ymd'),
                'end' => $periodEnd->format('Ymd'),
                'format' => 'JSON',
            ]);

        $source = 'NASA POWER (T2M)';
        $validReadings = [];

        if ($response->successful()) {
            $daily = $response->json('properties.parameter.T2M', []);
            $validReadings = array_filter(
                $daily,
                fn ($celsius) => is_numeric($celsius) && (float) $celsius !== self::FILL_VALUE
            );
        }

        // NASA POWER is the primary source — Open-Meteo only steps in when it's down or gave
        // nothing usable, so a single provider's outage can't take the whole signal down.
        if ($validReadings === []) {
            $fallback = $this->openMeteo->fetchDaily($region, $periodStart, $periodEnd, 'temperature_2m_mean');

            if ($fallback === null) {
                if ($response->failed()) {
                    throw new RuntimeException("NASA POWER request failed with status {$response->status()} for region {$region->region_id}, and Open-Meteo fallback also had no data.");
                }

                return null;
            }

            $validReadings = $fallback;
            $source = 'Open-Meteo (fallback — NASA POWER unavailable)';
        }

        // Average, not summed — unlike rainfall, daily temperatures don't accumulate.
        $averageCelsius = array_sum($validReadings) / count($validReadings);
        $signalType = SignalType::query()->where('code', $this->signalTypeCode())->firstOrFail();
        $roundedAverage = round($averageCelsius, 4);

        $signal = RegionSignal::query()->updateOrCreate(
            [
                'region_id' => $region->region_id,
                'signal_type_id' => $signalType->signal_type_id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ],
            [
                'value' => $roundedAverage,
                'raw_metadata' => ['daily_c' => $validReadings, 'days_reported' => count($validReadings)],
                'source' => $source,
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), $roundedAverage);

        return $signal;
    }
}
