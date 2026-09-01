<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Models\RegionForecastSignal;
use App\Models\SignalType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * River-discharge forecast (m³/s) via the Open-Meteo Flood API / GloFAS — the forecast lane
 * (BUILD_PLAN.md T4). Pulls the daily forward series from the issue date out to the horizon.
 *
 * v1 keeps only the latest issuance, so each run replaces this region+signal's whole forecast
 * series in one transaction — that also prunes target dates that have dropped out of the window
 * (yesterday's "+14 days" is today's "+13"). Issuance history is T5.
 *
 * The normal-flow reference each day's value gets scored against lives in scoring, not here
 * (scoring_calibration_parameters RIVER_DISCHARGE_MIN/MAX, per-region overridable).
 */
class RiverDischargeForecastService implements ForecastIngestionService
{
    public const SIGNAL_CODE = 'RIVER_DISCHARGE';

    public function __construct(private readonly OpenMeteoFloodClient $flood) {}

    public function signalTypeCode(): string
    {
        return self::SIGNAL_CODE;
    }

    public function ingestForecastForRegion(Region $region, Carbon $issuedAt, int $horizonDays): Collection
    {
        $issuedAt = $issuedAt->copy()->startOfDay();
        $series = $this->flood->dailyDischarge($region, $issuedAt, $issuedAt->copy()->addDays($horizonDays));

        if ($series === null) {
            return collect();
        }

        $signalType = SignalType::query()->where('code', self::SIGNAL_CODE)->firstOrFail();
        $now = now()->toDateTimeString();
        $metadata = json_encode(['series' => $series], JSON_THROW_ON_ERROR);

        $rows = [];
        foreach ($series as $date => $value) {
            $targetDate = Carbon::parse($date)->startOfDay();

            // A flood API window can include days before the issue date — keep only the forward series.
            if ($targetDate->lt($issuedAt)) {
                continue;
            }

            $rows[] = [
                'region_id' => $region->region_id,
                'signal_type_id' => $signalType->signal_type_id,
                'forecast_issued_at' => $issuedAt->toDateString(),
                'target_date' => $targetDate->toDateString(),
                'lead_days' => (int) $issuedAt->diffInDays($targetDate),
                'value' => round($value, 4),
                'raw_metadata' => $metadata,
                'source' => 'Open-Meteo Flood API (GloFAS)',
                'ingested_at' => $now,
            ];
        }

        if ($rows === []) {
            return collect();
        }

        DB::transaction(function () use ($region, $signalType, $rows) {
            RegionForecastSignal::query()
                ->where('region_id', $region->region_id)
                ->where('signal_type_id', $signalType->signal_type_id)
                ->delete();

            RegionForecastSignal::query()->insert($rows);
        });

        return RegionForecastSignal::query()
            ->where('region_id', $region->region_id)
            ->where('signal_type_id', $signalType->signal_type_id)
            ->orderBy('target_date')
            ->get();
    }
}
