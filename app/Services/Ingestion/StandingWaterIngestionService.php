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
 * Standing water via JRC's Global Surface Water "occurrence" layer — served as public,
 * anonymous PNG map tiles (no account, unlike the GeoTIFF/Earth Engine access paths for
 * the same dataset). Each pixel encodes "what % of the time was water detected here,
 * 1984-2021" as a color on a white (0%) -> pink -> blue (100%) gradient; fully transparent
 * pixels mean "never water."
 *
 * A region's stored coordinate is its administrative centre (a town/city point), which is
 * built on dry land almost everywhere — sampling that single pixel reads ~0% for every
 * region regardless of how close a river or wetland actually is, which was confirmed
 * empirically before this was written. So this averages a small grid of pixels around
 * the point (roughly a 3km radius) instead of reading one pixel — "how much standing
 * water is in this LGA's vicinity," which is both what Malaria/Flood risk actually care
 * about and the only sampling that produces a non-degenerate signal from this dataset.
 */
class StandingWaterIngestionService implements SignalIngestionService
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://storage.googleapis.com/global-surface-water/tiles2021/occurrence';

    private const ZOOM = 12;

    // ~3km radius, sampled every 3px, around the region's point — see class docblock.
    private const SAMPLE_RADIUS_PX = 40;

    private const SAMPLE_STEP_PX = 3;

    public function signalTypeCode(): string
    {
        return 'STANDING_WATER';
    }

    public function ingestForRegion(Region $region, Carbon $periodStart, Carbon $periodEnd): ?RegionSignal
    {
        if ($region->latitude === null || $region->longitude === null) {
            return null;
        }

        $occurrence = $this->averageAreaOccurrence((float) $region->latitude, (float) $region->longitude);

        if ($occurrence === null) {
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
                'value' => $occurrence,
                'raw_metadata' => ['zoom' => self::ZOOM, 'sample_radius_px' => self::SAMPLE_RADIUS_PX],
                'source' => 'JRC Global Surface Water (occurrence, 1984-2021)',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), $occurrence);

        return $signal;
    }

    /**
     * Average % water occurrence over a small pixel grid around the point. Returns null if
     * the tile doesn't exist (e.g. open ocean beyond the dataset's coverage).
     */
    private function averageAreaOccurrence(float $lat, float $lon): ?float
    {
        [$xTileF, $yTileF] = $this->latLonToTileFraction($lat, $lon, self::ZOOM);
        $xTile = (int) floor($xTileF);
        $yTile = (int) floor($yTileF);
        $centerPx = ($xTileF - $xTile) * 256;
        $centerPy = ($yTileF - $yTile) * 256;

        $response = Http::withOptions(['verify' => $this->caBundle()])
            ->timeout(30)
            ->get(self::BASE_URL.'/'.self::ZOOM."/{$xTile}/{$yTile}.png");

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException("JRC tile request failed with status {$response->status()} for tile {$xTile}/{$yTile}.");
        }

        $image = @imagecreatefromstring($response->body());

        if ($image === false) {
            return null;
        }

        $sum = 0.0;
        $count = 0;

        for ($dx = -self::SAMPLE_RADIUS_PX; $dx <= self::SAMPLE_RADIUS_PX; $dx += self::SAMPLE_STEP_PX) {
            for ($dy = -self::SAMPLE_RADIUS_PX; $dy <= self::SAMPLE_RADIUS_PX; $dy += self::SAMPLE_STEP_PX) {
                $x = (int) round($centerPx + $dx);
                $y = (int) round($centerPy + $dy);

                if ($x < 0 || $x > 255 || $y < 0 || $y > 255) {
                    continue;
                }

                $colors = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $sum += $this->occurrenceFromColor($colors['red'], $colors['green'], $colors['blue'], $colors['alpha']);
                $count++;
            }
        }

        imagedestroy($image);

        return $count > 0 ? round($sum / $count, 2) : null;
    }

    /**
     * JRC's occurrence palette is a two-segment gradient: 0% = white, 50% = light pink
     * (#ffbbbb), 100% = blue. Fully transparent (GD alpha 127) means "never water."
     */
    private function occurrenceFromColor(int $red, int $green, int $blue, int $alpha): float
    {
        if ($alpha >= 127) {
            return 0.0;
        }

        if ($red >= 200) {
            // White -> pink segment: only green/blue fall, red stays high.
            $t = (255 - $green) / (255 - 187);

            return max(0.0, min(50.0, $t * 50));
        }

        // Pink -> blue segment: red falls from 187 to 0.
        $t = (187 - $red) / 187;

        return max(50.0, min(100.0, 50 + $t * 50));
    }

    /**
     * Standard slippy-map (Web Mercator) tile math — fractional so the caller can find the
     * exact sub-pixel position of a point within its tile, not just which tile it's in.
     *
     * @return array{0: float, 1: float}
     */
    private function latLonToTileFraction(float $lat, float $lon, int $zoom): array
    {
        $n = 2 ** $zoom;
        $xTile = ($lon + 180) / 360 * $n;
        $latRad = deg2rad($lat);
        $yTile = (1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n;

        return [$xTile, $yTile];
    }
}
