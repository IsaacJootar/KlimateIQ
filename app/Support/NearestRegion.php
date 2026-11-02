<?php

namespace App\Support;

use App\Models\Region;
use Illuminate\Support\Collection;

/**
 * Enterprise pivot, Module 3 (docs/ENTERPRISE_BUILD_PLAN.md) — finds the closest seeded LGA to
 * an arbitrary coordinate (a client's own site — a bank branch, a tower — that isn't one of the
 * 774 seeded regions).
 *
 * This is the whole of Module 3's scope, after investigation (see the module's own notes in
 * docs/ENTERPRISE_BUILD_PLAN.md): region_signals and region_scores both require a real,
 * persisted region_id — not nullable, foreign-key constrained — so true point-level ingestion
 * for an ad-hoc coordinate would mean creating synthetic Region rows mixed into the same table
 * that backs "774 real Nigerian LGAs" everywhere else in the app (the public API, sector
 * rollups, the README's own claims). Too invasive for what this needs to do.
 *
 * Nearest-LGA snapping reuses the entire existing scoring pipeline with zero new ingestion, as
 * long as the result is never presented as an exact point score — see
 * App\Services\Portfolio\PortfolioRollupService, which labels it "approximated" with the
 * distance shown, the same fail-safe-over-silent-guess discipline as everywhere else in this
 * codebase (an uncalibrated river reach shown as "not calibrated yet" rather than a fabricated
 * number, a coverage gap skipped rather than treated as zero risk).
 *
 * Haversine distance computed in plain PHP over the seeded region set (774 rows — cheap; no
 * PostGIS or DB-side geospatial function is used anywhere else in this codebase, so there's
 * nothing to build on there).
 */
class NearestRegion
{
    /**
     * Beyond this distance a "nearest LGA" isn't a meaningful stand-in any more — a coordinate
     * this far from any seeded region (e.g. just outside Nigeria's borders) should say so
     * honestly rather than approximate against something irrelevant. An engineering judgement
     * call, not a measured threshold — roughly the scale of a large LGA's own extent.
     */
    public const MAX_USEFUL_DISTANCE_KM = 50.0;

    /**
     * @return array{region: Region, distance_km: float}|null null when nothing seeded is within
     *                                                        MAX_USEFUL_DISTANCE_KM.
     */
    public static function find(float $latitude, float $longitude): ?array
    {
        $nearest = self::candidates()
            ->map(fn (Region $region) => [
                'region' => $region,
                'distance_km' => self::haversineKm($latitude, $longitude, (float) $region->latitude, (float) $region->longitude),
            ])
            ->sortBy('distance_km')
            ->first();

        if ($nearest === null || $nearest['distance_km'] > self::MAX_USEFUL_DISTANCE_KM) {
            return null;
        }

        return $nearest;
    }

    /**
     * @return Collection<int, Region>
     */
    private static function candidates(): Collection
    {
        return Region::query()->whereNotNull('latitude')->whereNotNull('longitude')->get();
    }

    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
