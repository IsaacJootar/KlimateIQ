<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use Illuminate\Support\Carbon;

/**
 * BUILD_PLAN.md T5 — the ensemble counterpart of ForecastIngestionService. A source that can
 * fetch *many* forecast series for a region (one per ensemble member) and write them into
 * `region_forecast_signals` tagged with a member id.
 *
 * Same discipline as the other ingestion contracts: never throws for a coverage gap, so one
 * region's gap can't abort a scheduled run over many. Returns the number of member rows written.
 */
interface EnsembleForecastIngestionService
{
    /**
     * The signal_types.code this service produces (e.g. 'RIVER_DISCHARGE').
     */
    public function signalTypeCode(): string;

    /**
     * Fetch this source's ensemble for one region as of $issuedAt, out to $horizonDays, and
     * replace this region+signal's member rows in region_forecast_signals. The 'control' row
     * written by the deterministic ForecastIngestionService is left untouched.
     */
    public function ingestEnsembleForRegion(Region $region, Carbon $issuedAt, int $horizonDays): int;
}
