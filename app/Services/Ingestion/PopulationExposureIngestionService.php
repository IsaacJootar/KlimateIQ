<?php

namespace App\Services\Ingestion;

use App\Events\RegionSignalIngested;
use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\SignalType;
use Illuminate\Support\Carbon;

/**
 * Population, from the region's own regions.population column (see population:import) rather
 * than a live per-request external call — every other source in this app hits a real API on
 * every ingestion run, this one deliberately doesn't, because no reliable live API for LGA-level
 * Nigerian population exists (see docs/INGESTION_GUIDE.md for what was actually checked). A
 * person count doesn't move week to week the way weather does, so re-running population:import
 * when a newer dataset shows up is the right refresh model, not hitting an external service on
 * a schedule for a number that hasn't changed.
 *
 * Still implements the same interface and still runs on the same schedule as every other
 * source, for architectural consistency — one signal_types row, one region_signals row per
 * period, no special-casing anywhere else in ingestion, scoring, or the dashboard.
 */
class PopulationExposureIngestionService implements SignalIngestionService
{
    public function signalTypeCode(): string
    {
        return 'POPULATION_EXPOSURE';
    }

    public function ingestForRegion(Region $region, Carbon $periodStart, Carbon $periodEnd): ?RegionSignal
    {
        if ($region->population === null) {
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
                'value' => $region->population,
                'raw_metadata' => ['imported_via' => 'population:import'],
                'source' => 'UNFPA/US Census Bureau via HDX (2020 LGA-level projection)',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), (float) $region->population);

        return $signal;
    }
}
