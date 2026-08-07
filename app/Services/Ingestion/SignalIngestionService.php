<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Models\RegionSignal;
use Illuminate\Support\Carbon;

/**
 * A single environmental/climate data source, normalizing its readings into RegionSignal rows.
 *
 * Every source (rainfall, standing water, temperature, ...) implements this same contract, so
 * a new source is a new class — nothing in the scheduler, the scoring engine, or the dashboard
 * has to change to add or remove one. See the developer guide for how to plug in a new source.
 */
interface SignalIngestionService
{
    /**
     * The signal_types.code this service produces (e.g. 'RAINFALL').
     */
    public function signalTypeCode(): string;

    /**
     * Fetch and normalize this source's reading for one region over one period, upserting it
     * into region_signals. Returns null if the source has no data for that region/period
     * (e.g. outside coverage) rather than throwing — a gap in one source must not abort a
     * scheduled run covering many regions and sources.
     */
    public function ingestForRegion(Region $region, Carbon $periodStart, Carbon $periodEnd): ?RegionSignal;
}
