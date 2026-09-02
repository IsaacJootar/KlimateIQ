<?php

namespace App\Services\Scoring;

use App\Models\Region;
use App\Models\RegionForecastSignal;
use App\Models\RegionScoringConfig;
use App\Models\RegionSignal;
use App\Models\ScoringIndex;
use App\Services\Scoring\Concerns\NormalisesSignals;
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

        // signal_type_id => [target_date => value], forward of the issue date.
        $series = RegionForecastSignal::query()
            ->where('region_id', $region->region_id)
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

        $daily = [];
        foreach ($days as $date) {
            $weightedSum = 0.0;
            $totalWeight = 0.0;
            $signals = [];

            foreach ($configs as $config) {
                $value = $series->get($config->signal_type_id)?->get($date)
                    ?? $observedFallback->get($config->signal_type_id);
                if ($value === null) {
                    continue;
                }

                [$min, $max] = $this->calibrationBounds($index, $region, $config->signalType->code);
                $higherIsWorse = $config->higher_is_worse ?? $config->signalType->higher_is_worse;
                $normalized = $this->normalize($value, $min, $max, $higherIsWorse);
                $weight = (float) $config->weight;

                $weightedSum += $normalized * $weight;
                $totalWeight += $weight;
                $signals[$config->signalType->code] = ['raw_value' => $value, 'normalized_score' => round($normalized, 2)];
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
}
