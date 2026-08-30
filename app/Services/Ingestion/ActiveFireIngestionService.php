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
 * Satellite active-fire detections via NASA FIRMS (VIIRS aboard NOAA-20). This is a
 * confirmation / backtest series, not a scoring input — it carries weight 0 on Wildfire Risk,
 * so it shows up in the score breakdown ("we called fire weather high, and here's what
 * actually burned") without moving the number.
 *
 * NOAA-20 rather than Suomi NPP: SNPP product delivery ceases 2026-11-01. NOAA-21 is the
 * newer bird; NOAA-20 has the longer stable record for now.
 *
 * The FIRMS area API serves at most 5 days per request and only ~2 months of NRT history, so
 * this pulls the 5 days ending at the period end — a subset of the 7-day window, which is fine
 * for a confirmation series. A valid-but-empty response means zero fires, which is real data:
 * the signal is written with value 0, not skipped.
 *
 * No map key configured (config services.firms.map_key) → the service is a no-op, exactly like
 * VegetationIngestionService without Earthdata credentials.
 */
class ActiveFireIngestionService implements SignalIngestionService
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://firms.modaps.eosdis.nasa.gov/api/area/csv';

    private const SOURCE = 'VIIRS_NOAA20_NRT';

    private const DAY_RANGE = 5;

    /** Degrees of padding around the LGA-seat centroid — roughly a ±22 km box. */
    private const BBOX_PAD = 0.2;

    /** Cap on how many individual detections we keep in raw_metadata. */
    private const MAX_DETAILS = 200;

    public function signalTypeCode(): string
    {
        return 'ACTIVE_FIRE';
    }

    public function ingestForRegion(Region $region, Carbon $periodStart, Carbon $periodEnd): ?RegionSignal
    {
        if ($region->latitude === null || $region->longitude === null) {
            return null;
        }

        $mapKey = config('services.firms.map_key');

        if (empty($mapKey)) {
            return null;
        }

        $lat = (float) $region->latitude;
        $lon = (float) $region->longitude;
        $bbox = implode(',', [
            round($lon - self::BBOX_PAD, 4),
            round($lat - self::BBOX_PAD, 4),
            round($lon + self::BBOX_PAD, 4),
            round($lat + self::BBOX_PAD, 4),
        ]);

        $url = sprintf(
            '%s/%s/%s/%s/%d/%s',
            self::BASE_URL,
            $mapKey,
            self::SOURCE,
            $bbox,
            self::DAY_RANGE,
            $periodEnd->toDateString(),
        );

        $response = Http::withOptions(['verify' => $this->caBundle()])->timeout(45)->get($url);

        if ($response->failed()) {
            return null;
        }

        $body = trim($response->body());

        // FIRMS returns plain-text errors ("Invalid MAP_KEY.", "Invalid day range...") with a
        // 200 status. A real payload always starts with the CSV header.
        if (! str_starts_with($body, 'latitude,longitude')) {
            throw new RuntimeException("FIRMS returned an unexpected response for region {$region->region_id}: ".mb_substr($body, 0, 120));
        }

        $detections = $this->parse($body);
        $count = count($detections);
        $totalFrp = round(array_sum(array_column($detections, 'frp')), 2);

        $signalType = SignalType::query()->where('code', $this->signalTypeCode())->firstOrFail();

        $signal = RegionSignal::query()->updateOrCreate(
            [
                'region_id' => $region->region_id,
                'signal_type_id' => $signalType->signal_type_id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ],
            [
                'value' => $count,
                'raw_metadata' => [
                    'detections' => $count,
                    'total_frp_mw' => $totalFrp,
                    'source_product' => self::SOURCE,
                    'days_covered' => self::DAY_RANGE,
                    'bbox' => $bbox,
                    'sample' => array_slice($detections, 0, self::MAX_DETAILS),
                ],
                'source' => 'NASA FIRMS ('.self::SOURCE.')',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), (float) $count);

        return $signal;
    }

    /**
     * @return list<array{acq_date: string, latitude: float, longitude: float, frp: float, confidence: string}>
     */
    private function parse(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $header = str_getcsv((string) array_shift($lines));

        $col = array_flip($header);
        $out = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $row = str_getcsv($line);

            $out[] = [
                'acq_date' => $row[$col['acq_date']] ?? '',
                'latitude' => isset($row[$col['latitude']]) ? (float) $row[$col['latitude']] : 0.0,
                'longitude' => isset($row[$col['longitude']]) ? (float) $row[$col['longitude']] : 0.0,
                'frp' => isset($row[$col['frp']]) && is_numeric($row[$col['frp']]) ? (float) $row[$col['frp']] : 0.0,
                'confidence' => $row[$col['confidence']] ?? '',
            ];
        }

        return $out;
    }
}
