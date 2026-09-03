<?php

namespace App\Services\Ingestion\Concerns;

use App\Models\Region;
use App\Models\RegionForecastSignal;
use App\Models\SignalType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The write half every EnsembleForecastIngestionService shares (BUILD_PLAN.md T5). Takes a
 * `memberId => (date => value)` map and replaces this region+signal's whole *ensemble* series
 * in one transaction — every row with `member != 'control'`, so the deterministic series written
 * by PersistsForecastSeries is left alone. Latest issuance wins; days before the issue date and
 * stale target dates are pruned by the same delete-then-insert.
 */
trait PersistsEnsembleForecastSeries
{
    /**
     * @param  array<string, array<string, float>>  $memberSeries  memberId => (date => value)
     * @return int  member rows written
     */
    protected function persistEnsembleSeries(Region $region, string $signalCode, string $source, Carbon $issuedAt, array $memberSeries): int
    {
        $issuedAt = $issuedAt->copy()->startOfDay();
        $signalType = SignalType::query()->where('code', $signalCode)->firstOrFail();
        $now = now()->toDateTimeString();

        $rows = [];
        foreach ($memberSeries as $memberId => $series) {
            $metadata = json_encode(['series' => $series], JSON_THROW_ON_ERROR);

            foreach ($series as $date => $value) {
                if (! is_numeric($value)) {
                    continue;
                }

                $targetDate = Carbon::parse($date)->startOfDay();
                if ($targetDate->lt($issuedAt)) {
                    continue;
                }

                $rows[] = [
                    'region_id' => $region->region_id,
                    'signal_type_id' => $signalType->signal_type_id,
                    'member' => $memberId,
                    'forecast_issued_at' => $issuedAt->toDateString(),
                    'target_date' => $targetDate->toDateString(),
                    'lead_days' => (int) $issuedAt->diffInDays($targetDate),
                    'value' => round((float) $value, 4),
                    'raw_metadata' => $metadata,
                    'source' => $source,
                    'ingested_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($region, $signalType, $rows) {
            RegionForecastSignal::query()
                ->where('region_id', $region->region_id)
                ->where('signal_type_id', $signalType->signal_type_id)
                ->where('member', '!=', 'control')
                ->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                RegionForecastSignal::query()->insert($chunk);
            }
        });

        return count($rows);
    }
}
