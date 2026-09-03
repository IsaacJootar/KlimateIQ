<?php

namespace App\Services\Ingestion\Concerns;

use App\Models\Region;
use App\Models\RiverReach;
use Illuminate\Support\Collection;

/**
 * The reaches to sample GloFAS at for a region's river discharge (T4/T5 follow-up). A multi-river
 * LGA (Lokoja, Bassa) has curated `river_reaches` rows — the Niger and the Benue separately. An
 * LGA with none is sampled once at its centroid, tagged 'centroid' — the pre-existing behaviour.
 *
 * @return Collection<int, array{reach: string, river: ?string, lat: ?float, lon: ?float}>
 */
trait SamplesRiverReaches
{
    protected function reachesFor(Region $region): Collection
    {
        $reaches = RiverReach::query()->where('region_id', $region->region_id)->get();

        if ($reaches->isEmpty()) {
            return collect([['reach' => 'centroid', 'river' => null, 'lat' => null, 'lon' => null]]);
        }

        return $reaches->map(fn (RiverReach $r) => [
            'reach' => $r->reach,
            'river' => $r->river,
            'lat' => $r->latitude,
            'lon' => $r->longitude,
        ])->values();
    }
}
