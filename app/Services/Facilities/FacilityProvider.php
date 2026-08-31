<?php

namespace App\Services\Facilities;

use App\Models\Facility;
use App\Models\Region;
use Illuminate\Support\Collection;

/**
 * Where "the schools and health centres in this LGA" comes from. One implementation today —
 * Grid3StaticProvider, reading the local `facilities` table. Swapping in a live source (e.g.
 * healthsites.io) later is a new class implementing this interface plus one line in
 * config/facilities.php; nothing downstream changes.
 */
interface FacilityProvider
{
    /**
     * Facilities of the given types for a region, most relevant first, capped at $limit.
     *
     * @param  array<string>  $types  health | school | market | water_point | shelter
     * @return Collection<int, Facility>
     */
    public function forRegion(Region $region, array $types, int $limit = 3): Collection;

    /**
     * Everything on record for a region (for a "see all" view), grouped by type.
     *
     * @return Collection<string, Collection<int, Facility>>
     */
    public function allForRegion(Region $region): Collection;

    /** A short attribution line, e.g. "GRID3, 2023". */
    public function attribution(): string;
}
