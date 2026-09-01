<?php

namespace App\Services\Ingestion;

use App\Events\RegionSignalIngested;
use App\Models\Region;
use App\Models\RegionSignal;
use App\Models\SignalType;
use Illuminate\Support\Carbon;

/**
 * River discharge (m³/s) via the Open-Meteo Flood API / GloFAS — the observed lane. Builds a
 * per-LGA history of how much water the river has been carrying, which is what the Riverine
 * Flood Forecast index (BUILD_PLAN.md T4) measures its forecast against, and a future input
 * for Flood Risk itself.
 *
 * Discharge is a rate, so the weekly value is the mean of the daily readings over the period,
 * not a sum. Returns null (not zero) for an unmodelled reach or an API gap.
 */
class RiverDischargeIngestionService implements SignalIngestionService
{
    public const SIGNAL_CODE = 'RIVER_DISCHARGE';

    public function __construct(private readonly OpenMeteoFloodClient $flood) {}

    public function signalTypeCode(): string
    {
        return self::SIGNAL_CODE;
    }

    public function ingestForRegion(Region $region, Carbon $periodStart, Carbon $periodEnd): ?RegionSignal
    {
        $series = $this->flood->dailyDischarge($region, $periodStart, $periodEnd);

        if ($series === null) {
            return null;
        }

        $mean = round(array_sum($series) / count($series), 4);
        $signalType = SignalType::query()->where('code', self::SIGNAL_CODE)->firstOrFail();

        $signal = RegionSignal::query()->updateOrCreate(
            [
                'region_id' => $region->region_id,
                'signal_type_id' => $signalType->signal_type_id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ],
            [
                'value' => $mean,
                'raw_metadata' => ['daily_m3s' => $series, 'days_reported' => count($series)],
                'source' => 'Open-Meteo Flood API (GloFAS)',
                'ingested_at' => now(),
            ]
        );

        RegionSignalIngested::dispatch($signalType->signal_type_id, $region->region_id, $periodStart->toDateString(), $mean);

        return $signal;
    }
}
