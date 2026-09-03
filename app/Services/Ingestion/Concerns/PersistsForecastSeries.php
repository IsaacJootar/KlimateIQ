<?php

namespace App\Services\Ingestion\Concerns;

use App\Models\Region;
use App\Models\RegionForecastSignal;
use App\Models\SignalType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The write half every ForecastIngestionService shares (BUILD_PLAN.md T4): take a fetched
 * date => value series and replace this region+signal's whole *control* forecast series in one
 * transaction. Latest issuance wins, and the delete also prunes target dates that fell out of
 * the window (yesterday's "+14 days" is today's "+13"). Days before the issue date are dropped.
 *
 * Scoped to `member = 'control'` (T5): the deterministic series and the ensemble members
 * (PersistsEnsembleForecastSeries) share the table but replace each other's rows independently.
 */
trait PersistsForecastSeries
{
    /**
     * @param  array<string, float>  $series  date => value; may include days before $issuedAt
     * @param  string  $reach  which named river reach this series is for ('centroid' = the
     *                          single-point default, and every non-discharge signal)
     * @return Collection<int, RegionForecastSignal>
     */
    protected function persistForecastSeries(Region $region, string $signalCode, string $source, Carbon $issuedAt, array $series, string $reach = 'centroid'): Collection
    {
        $issuedAt = $issuedAt->copy()->startOfDay();
        $signalType = SignalType::query()->where('code', $signalCode)->firstOrFail();
        $now = now()->toDateTimeString();
        $metadata = json_encode(['series' => $series], JSON_THROW_ON_ERROR);

        $rows = [];
        foreach ($series as $date => $value) {
            $targetDate = Carbon::parse($date)->startOfDay();

            if ($targetDate->lt($issuedAt)) {
                continue;
            }

            $rows[] = [
                'region_id' => $region->region_id,
                'signal_type_id' => $signalType->signal_type_id,
                'member' => 'control',
                'reach' => $reach,
                'forecast_issued_at' => $issuedAt->toDateString(),
                'target_date' => $targetDate->toDateString(),
                'lead_days' => (int) $issuedAt->diffInDays($targetDate),
                'value' => round($value, 4),
                'raw_metadata' => $metadata,
                'source' => $source,
                'ingested_at' => $now,
            ];
        }

        if ($rows === []) {
            return collect();
        }

        DB::transaction(function () use ($region, $signalType, $rows, $reach) {
            RegionForecastSignal::query()
                ->where('region_id', $region->region_id)
                ->where('signal_type_id', $signalType->signal_type_id)
                ->where('member', 'control')
                ->where('reach', $reach)
                ->delete();

            RegionForecastSignal::query()->insert($rows);
        });

        return RegionForecastSignal::query()
            ->where('region_id', $region->region_id)
            ->where('signal_type_id', $signalType->signal_type_id)
            ->where('member', 'control')
            ->where('reach', $reach)
            ->orderBy('target_date')
            ->get();
    }
}
