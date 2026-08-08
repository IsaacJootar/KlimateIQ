<?php

namespace App\Services\Ingestion;

use App\Events\RegionSignalIngested;
use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\SignalType;
use App\Services\Earthdata\AppEearsClient;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Vegetation (NDVI) via NASA's MODIS MOD13Q1.061 product, through the AppEEARS API.
 *
 * Unlike Rainfall/Temperature, this is a genuinely async task-based API (submit, poll,
 * download), and MOD13Q1 is a 16-day composite — a new value only appears roughly every
 * 16 days, not every week. So this queries a wider lookback window than the weekly period
 * it's called with, and takes the most recent composite inside that window.
 */
class VegetationIngestionService implements SignalIngestionService
{
    private const PRODUCT = 'MOD13Q1.061';

    private const LAYER = '_250m_16_days_NDVI';

    // Wide enough to reliably catch at least one 16-day composite even if the most
    // recent one is delayed in processing.
    private const LOOKBACK_DAYS = 45;

    public function __construct(private readonly AppEearsClient $client) {}

    public function signalTypeCode(): string
    {
        return 'VEGETATION';
    }

    public function ingestForRegion(Region $region, Carbon $periodStart, Carbon $periodEnd): ?RegionSignal
    {
        if ($region->latitude === null || $region->longitude === null) {
            return null;
        }

        if (! $this->client->isConfigured()) {
            return null;
        }

        $queryStart = $periodEnd->copy()->subDays(self::LOOKBACK_DAYS);

        $taskId = $this->client->submitPointTask(
            taskName: "gano-ai-{$region->region_id}-{$periodEnd->toDateString()}",
            product: self::PRODUCT,
            layer: self::LAYER,
            pointId: (string) $region->region_id,
            latitude: (float) $region->latitude,
            longitude: (float) $region->longitude,
            start: $queryStart,
            end: $periodEnd,
        );

        if (! $this->client->waitUntilDone($taskId)) {
            throw new RuntimeException("AppEEARS task {$taskId} for region {$region->region_id} did not finish in time.");
        }

        $csv = $this->client->fetchResultsCsv($taskId);

        if ($csv === null) {
            return null;
        }

        $latest = $this->latestReading($csv);

        if ($latest === null) {
            return null;
        }

        $signalType = SignalType::query()->where('code', $this->signalTypeCode())->firstOrFail();

        $signal = RegionSignal::query()->updateOrCreate(
            [
                'region_id' => $region->region_id,
                'signal_type_id' => $signalType->signal_type_id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ],
            [
                'value' => $latest['ndvi'],
                'raw_metadata' => ['observed_date' => $latest['date'], 'product' => self::PRODUCT],
                'source' => 'NASA AppEEARS (MOD13Q1.061 NDVI)',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), $latest['ndvi']);

        return $signal;
    }

    /**
     * Parse the AppEEARS point CSV and return the most recent valid NDVI reading, since a
     * 45-day window typically contains 2-3 composite periods and only the newest matters.
     *
     * @return array{date: string, ndvi: float}|null
     */
    private function latestReading(string $csv): ?array
    {
        $lines = array_filter(preg_split('/\r\n|\r|\n/', trim($csv)));
        $header = str_getcsv(array_shift($lines));

        $dateIndex = array_search('Date', $header, true);
        $ndviIndex = null;

        foreach ($header as $i => $column) {
            if (str_ends_with($column, '_NDVI')) {
                $ndviIndex = $i;
                break;
            }
        }

        if ($dateIndex === false || $ndviIndex === null) {
            return null;
        }

        $best = null;

        foreach ($lines as $line) {
            $row = str_getcsv($line);
            $ndvi = $row[$ndviIndex] ?? null;

            if (! is_numeric($ndvi)) {
                continue;
            }

            $date = $row[$dateIndex];

            if ($best === null || $date > $best['date']) {
                $best = ['date' => $date, 'ndvi' => round((float) $ndvi, 4)];
            }
        }

        return $best;
    }
}
