<?php

namespace App\Services\Scoring;

use App\Models\Region;
use App\Models\RegionForecastSignal;
use App\Models\RegionScoringConfig;
use App\Models\RegionSignal;
use App\Models\RiverReach;
use App\Models\ScoringIndex;
use App\Services\Scoring\Concerns\NormalisesSignals;
use App\Support\IndexCalibration;
use Illuminate\Support\Carbon;

/**
 * Scores a forward index (BUILD_PLAN.md T4) from the daily forecast signal series in
 * region_forecast_signals. Each forecast day is normalised the same way the observed engine
 * normalises an observed reading (shared NormalisesSignals trait) and combined by the index's
 * configured weights; the index score is the PEAK of that daily series, with the day it lands.
 *
 * For a single-signal index like Riverine Flood Forecast this is simply "the highest day of
 * forecast discharge, mapped against the LGA's normal-flow range" — the "threshold on forecast
 * discharge" the build plan describes, without needing a separate code path for the 1-signal
 * case.
 *
 * For an observed index being forward-scored (Flood Risk on forecast rainfall, Heat Stress on
 * forecast temperature) not every weighted signal has a forecast series — standing water is a
 * near-static occurrence layer, elevation is fixed, vegetation is a 16-day composite. Those fall
 * back to the region's latest observed reading held flat across the horizon, so the forecast
 * score is the same formula and weights as the observed one with only the forecastable signal
 * swapped — the two numbers stay directly comparable. The index still needs at least one signal
 * with a real forecast series, or it isn't forward-scored at all.
 */
class ForecastScoringStrategy
{
    use NormalisesSignals;

    public function code(): string
    {
        return 'forecast_formula';
    }

    public function score(ScoringIndex $index, Region $region, Carbon $issuedAt): ForecastScoreResult
    {
        $issuedAt = $issuedAt->copy()->startOfDay();

        $configs = RegionScoringConfig::query()
            ->with('signalType')
            ->where('index_id', $index->index_id)
            ->where('enabled', true)
            ->where(fn ($q) => $q->where('region_id', $region->region_id)->orWhereNull('region_id'))
            ->get()
            ->groupBy('signal_type_id')
            ->map(fn ($group) => $group->sortByDesc(fn ($c) => $c->region_id !== null)->first());

        // A single-signal river-discharge index (Riverine Flood Forecast) is scored per named
        // river reach — a confluence LGA sits on the Niger and the Benue and they flood
        // independently (BUILD_PLAN.md T4/T5 follow-up).
        if ($this->isSingleDischargeIndex($configs)) {
            return $this->scoreDischargeReaches($index, $region, $issuedAt, $configs->first());
        }

        // signal_type_id => [target_date => value], forward of the issue date. The deterministic
        // (control) series only — the ensemble members (T5) are scored by their own service.
        $series = RegionForecastSignal::query()
            ->where('region_id', $region->region_id)
            ->where('member', 'control')
            ->whereIn('signal_type_id', $configs->keys())
            ->whereDate('target_date', '>=', $issuedAt->toDateString())
            ->orderBy('target_date')
            ->get()
            ->groupBy('signal_type_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [$r->target_date->toDateString() => (float) $r->value]));

        $days = $series->flatMap(fn ($m) => $m->keys())->unique()->sort()->values();

        if ($days->isEmpty()) {
            return new ForecastScoreResult(null, null, null, 0, [], 'forecast-formula-v1');
        }

        // Weighted signals with no forecast series of their own fall back to the region's latest
        // observed reading, held flat across the horizon (see the class docblock).
        $observedFallback = RegionSignal::query()
            ->where('region_id', $region->region_id)
            ->whereIn('signal_type_id', $configs->keys()->reject(fn ($id) => $series->has($id)))
            ->orderByDesc('period_start')
            ->get()
            ->groupBy('signal_type_id')
            ->map(fn ($rows) => (float) $rows->first()->value);

        $daily = $this->scoreDailySeries($index, $region, $issuedAt, $configs, $series, $observedFallback);

        if ($daily === []) {
            return new ForecastScoreResult(null, null, null, $days->count(), [], 'forecast-formula-v1');
        }

        $peak = collect($daily)->sortByDesc('score')->first();

        return new ForecastScoreResult(
            score: (float) $peak['score'],
            peakDate: Carbon::parse($peak['date']),
            leadDaysToPeak: $peak['lead_days'],
            horizonDays: $days->count(),
            breakdown: ['daily' => $daily, 'peak' => $peak],
            scoringVersion: 'forecast-formula-v1',
        );
    }

    /**
     * True when the index is driven by RIVER_DISCHARGE alone (Riverine Flood Forecast) — the
     * case where a per-reach flood threshold is mandatory. A blended index that also weights
     * discharge (Flood Risk) is not affected: its other signals carry the score.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\RegionScoringConfig>  $configs
     */
    private function isSingleDischargeIndex(\Illuminate\Support\Collection $configs): bool
    {
        $enabled = $configs->filter(fn ($c) => (float) $c->weight > 0);

        return $enabled->count() === 1
            && $enabled->first()?->signalType?->code === 'RIVER_DISCHARGE';
    }

    /**
     * Score each river reach's forecast discharge separately (BUILD_PLAN.md T4/T5 follow-up).
     * The index score is the WORST reach — an emergency planner acts on whichever river is
     * about to flood — and `breakdown['reaches']` names them all. A reach with no calibrated
     * flood threshold (its own or the LGA-wide one) is dropped, not scored against a borrowed
     * number; if every reach is uncalibrated the result is "calibration pending".
     */
    private function scoreDischargeReaches(ScoringIndex $index, Region $region, Carbon $issuedAt, RegionScoringConfig $config): ForecastScoreResult
    {
        $rows = RegionForecastSignal::query()
            ->where('region_id', $region->region_id)
            ->where('member', 'control')
            ->where('signal_type_id', $config->signal_type_id)
            ->whereDate('target_date', '>=', $issuedAt->toDateString())
            ->orderBy('target_date')
            ->get()
            ->groupBy('reach');

        $rivers = RiverReach::query()->where('region_id', $region->region_id)->pluck('river', 'reach');

        // Once an LGA has named reaches, ignore any leftover 'centroid' rows from before it did —
        // the named reaches are the whole picture (T4/T5 follow-up).
        if ($rivers->isNotEmpty()) {
            $rows = $rows->reject(fn ($_, $reach) => $reach === 'centroid');
        }

        if ($rows->isEmpty()) {
            return new ForecastScoreResult(null, null, null, 0, [], 'forecast-formula-v1');
        }

        $configs = collect([$config->signal_type_id => $config]);
        $scored = [];
        $uncalibrated = [];
        $horizon = 0;

        foreach ($rows as $reach => $reachRows) {
            if (! IndexCalibration::hasRegionBound($index, $region, 'RIVER_DISCHARGE', $reach)) {
                $uncalibrated[] = $rivers[$reach] ?? $reach;

                continue;
            }

            $series = collect([$config->signal_type_id => $reachRows->mapWithKeys(
                fn ($r) => [$r->target_date->toDateString() => (float) $r->value],
            )]);
            $horizon = max($horizon, $series->first()->count());

            $daily = $this->scoreDailySeries($index, $region, $issuedAt, $configs, $series, collect(), $reach);
            if ($daily === []) {
                continue;
            }

            $peak = collect($daily)->sortByDesc('score')->first();
            $scored[] = [
                'reach' => $reach,
                'river' => $rivers[$reach] ?? ($reach === 'centroid' ? null : $reach),
                'score' => (float) $peak['score'],
                'peak_date' => $peak['date'],
                'lead_days' => $peak['lead_days'],
                'daily' => $daily,
            ];
        }

        if ($scored === []) {
            return new ForecastScoreResult(null, null, null, 0, [
                'status' => 'calibration_pending',
                'uncalibrated_reaches' => array_values(array_unique($uncalibrated)),
            ], 'forecast-formula-v1');
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $worst = $scored[0];

        return new ForecastScoreResult(
            score: $worst['score'],
            peakDate: Carbon::parse($worst['peak_date']),
            leadDaysToPeak: $worst['lead_days'],
            horizonDays: $horizon,
            breakdown: [
                'daily' => $worst['daily'],
                'peak' => collect($worst['daily'])->sortByDesc('score')->first(),
                'driving_reach' => $worst['reach'],
                'driving_river' => $worst['river'],
                'reaches' => array_map(fn ($r) => [
                    'reach' => $r['reach'], 'river' => $r['river'], 'score' => $r['score'],
                    'peak_date' => $r['peak_date'], 'lead_days' => $r['lead_days'], 'daily' => $r['daily'],
                ], $scored),
                'uncalibrated_reaches' => array_values(array_unique($uncalibrated)),
            ],
            scoringVersion: 'forecast-formula-v1',
        );
    }

    /**
     * Score each forecast day from a set of per-signal daily series plus an observed fallback for
     * weighted signals with no series of their own — the exact normalise + weight the observed
     * engine uses. Shared by the control path above and the per-member ensemble path
     * (EnsembleForecastScoringService, BUILD_PLAN.md T5), so a member score and the control score
     * differ only through the forecast values fed in.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\RegionScoringConfig>  $configs  keyed by signal_type_id
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<string, float>>  $seriesBySignalId  signal_type_id => (date => value)
     * @param  \Illuminate\Support\Collection<int, float>  $observedFallback  signal_type_id => latest observed value
     * @return list<array{date: string, lead_days: int, score: float, signals: array<string, array{raw_value: float, normalized_score: float}>}>
     */
    public function scoreDailySeries(
        ScoringIndex $index,
        Region $region,
        Carbon $issuedAt,
        \Illuminate\Support\Collection $configs,
        \Illuminate\Support\Collection $seriesBySignalId,
        \Illuminate\Support\Collection $observedFallback,
        ?string $reach = null,
    ): array {
        $issuedAt = $issuedAt->copy()->startOfDay();
        $days = $seriesBySignalId->flatMap(fn ($m) => $m->keys())->unique()->sort()->values();

        $daily = [];
        foreach ($days as $date) {
            $weightedSum = 0.0;
            $totalWeight = 0.0;
            $signals = [];

            foreach ($configs as $config) {
                $value = $seriesBySignalId->get($config->signal_type_id)?->get($date)
                    ?? $observedFallback->get($config->signal_type_id);
                if ($value === null) {
                    continue;
                }

                [$min, $max] = $this->calibrationBounds($index, $region, $config->signalType->code, $reach);
                $higherIsWorse = $config->higher_is_worse ?? $config->signalType->higher_is_worse;
                $normalized = $this->normalize((float) $value, $min, $max, $higherIsWorse);
                $weight = (float) $config->weight;

                $weightedSum += $normalized * $weight;
                $totalWeight += $weight;
                $signals[$config->signalType->code] = ['raw_value' => (float) $value, 'normalized_score' => round($normalized, 2)];
            }

            if ($totalWeight <= 0.0) {
                continue;
            }

            $daily[] = [
                'date' => $date,
                'lead_days' => (int) $issuedAt->diffInDays(Carbon::parse($date)),
                'score' => round(min(100, max(0, $weightedSum / $totalWeight)), 2),
                'signals' => $signals,
            ];
        }

        return $daily;
    }
}
