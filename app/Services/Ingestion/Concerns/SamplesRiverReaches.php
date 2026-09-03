<?php

namespace App\Services\Ingestion\Concerns;

use App\Models\Region;
use App\Models\RegionForecastSignal;
use App\Models\RiverReach;
use App\Models\SignalType;
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

    /**
     * Drop any leftover 'centroid' forecast-discharge rows for a region that now has named
     * reaches — a one-time cleanup on the first ingest after an LGA is added to river_reaches.
     */
    protected function pruneCentroidRows(Region $region, string $signalCode): void
    {
        if (RiverReach::query()->where('region_id', $region->region_id)->doesntExist()) {
            return;
        }

        $signalTypeId = SignalType::query()->where('code', $signalCode)->value('signal_type_id');
        RegionForecastSignal::query()
            ->where('region_id', $region->region_id)
            ->where('signal_type_id', $signalTypeId)
            ->where('reach', 'centroid')
            ->delete();
    }
}
