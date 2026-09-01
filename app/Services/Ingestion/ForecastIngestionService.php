<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Models\RegionForecastSignal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The forecast counterpart of SignalIngestionService (BUILD_PLAN.md T4). A source that can
 * fetch a *future* series for a region — daily steps from the issue date out to a horizon —
 * and upsert them into `region_forecast_signals`.
 *
 * Same discipline as the observed contract: returns whatever it wrote (an empty collection
 * when the source has no coverage for that region), never throws for a coverage gap, so one
 * region's gap can't abort a scheduled run over many.
 */
interface ForecastIngestionService
{
    /**
     * The signal_types.code this service produces (e.g. 'RIVER_DISCHARGE').
     */
    public function signalTypeCode(): string;

    /**
     * Fetch this source's forecast for one region as of $issuedAt, out to $horizonDays, and
     * upsert one row per forecast day into region_forecast_signals.
     *
     * @return Collection<int, RegionForecastSignal>
     */
    public function ingestForecastForRegion(Region $region, Carbon $issuedAt, int $horizonDays): Collection;
}
