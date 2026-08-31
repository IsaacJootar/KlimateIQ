<?php

namespace App\Services\Facilities;

use App\Models\Facility;
use App\Models\Region;
use Illuminate\Support\Collection;

/**
 * The default FacilityProvider — reads the `facilities` table imported from GRID3 Nigeria
 * (see FacilitySeeder). No network, no per-request cost. Relevance is just the seeded
 * sort_order for now (hand-ranked); a live provider could rank by distance.
 */
class Grid3StaticProvider implements FacilityProvider
{
    public function forRegion(Region $region, array $types, int $limit = 3): Collection
    {
        return Facility::query()
            ->where('region_id', $region->region_id)
            ->whereIn('type', $types)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            // Honour the caller's type priority — for a health advisory, health facilities
            // before schools — then the seeded rank within each type.
            ->sortBy(fn (Facility $f) => array_search($f->type, $types, true), SORT_NUMERIC)
            ->take($limit)
            ->values();
    }

    public function allForRegion(Region $region): Collection
    {
        return Facility::query()
            ->where('region_id', $region->region_id)
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy('type');
    }

    public function attribution(): string
    {
        $year = Facility::query()->max('source_year');

        return $year ? "GRID3, {$year}" : 'GRID3';
    }
}
