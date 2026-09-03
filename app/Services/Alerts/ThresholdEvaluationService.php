<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\RegionForecastScore;
use App\Models\RegionScore;
use App\Models\RegionSignal;
use App\Models\ThresholdConfig;
use App\Models\UserRegionSubscription;
use App\Notifications\ThresholdBreachedNotification;
use App\Services\Scoring\EnsembleForecastScoringService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reacts to a fresh score or signal reading: finds every active threshold that watches it,
 * decides whether it breached, and — for a config not already sitting on an open alert —
 * creates one and notifies its owner. Never called directly by the scoring or ingestion
 * layers; only ever reached via the RegionScoreCalculated / RegionSignalIngested listeners.
 */
class ThresholdEvaluationService
{
    /** How many prior periods feed an anomaly baseline's rolling mean/stddev. */
    private const ANOMALY_WINDOW = 8;

    public function evaluateForIndex(int $indexId, int $regionId, string $periodStart, ?float $score): void
    {
        if ($score === null) {
            return;
        }

        $configs = $this->applicableConfigs('index_id', $indexId, $regionId);

        foreach ($configs as $config) {
            $history = fn () => RegionScore::query()
                ->where('index_id', $indexId)
                ->where('region_id', $regionId)
                ->where('period_start', '<', $periodStart)
                ->orderByDesc('period_start')
                ->limit(self::ANOMALY_WINDOW)
                ->pluck('score')
                ->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => (float) $v);

            $this->evaluateConfig($config, $regionId, indexId: $indexId, signalTypeId: null, value: $score, history: $history);
        }
    }

    /**
     * BUILD_PLAN.md T4 M4 / T5 M3 — a fresh forecast for a forecast index. Two rule shapes:
     *
     *   - a fixed threshold fires on the forecast PEAK ("projected to reach 62");
     *   - a probability threshold fires when the ensemble gives at least `probability_threshold`
     *     percent chance of the peak reaching `threshold_value` ("≈72% chance of crossing 67").
     *
     * Either way, clearly flagged as a forecast, one open forecast alert per config that follows
     * the forecast (updated silently as it moves) and auto-resolves when it recedes below the
     * rule or its target date passes. Anomaly-type thresholds don't apply — no forecast baseline.
     */
    public function evaluateForForecast(int $indexId, int $regionId, ?float $peakScore, ?string $peakDate, ?int $leadDays): void
    {
        $configs = $this->applicableConfigs('index_id', $indexId, $regionId)
            ->load('index')
            ->filter(fn (ThresholdConfig $c) => ! $c->isAnomalyType()
                && ($c->watch_forecast || $c->isProbabilityType() || $c->index?->is_forecast));

        if ($configs->isEmpty()) {
            return;
        }

        $forecastRow = RegionForecastScore::query()
            ->where('index_id', $indexId)->where('region_id', $regionId)->first();
        $memberPeaks = array_map('floatval', $forecastRow?->breakdown['members'] ?? []);
        $p50 = $forecastRow?->p50 !== null ? (float) $forecastRow->p50 : null;

        $targetPassed = $peakDate !== null && Carbon::parse($peakDate)->isBefore(today());

        foreach ($configs as $config) {
            $openAlert = Alert::query()
                ->where('threshold_config_id', $config->threshold_config_id)
                ->where('is_forecast', true)
                ->where('status', 'OPEN')
                ->first();

            if ($config->isProbabilityType()) {
                $probability = $memberPeaks === []
                    ? null
                    : EnsembleForecastScoringService::exceedanceShare($memberPeaks, (float) $config->threshold_value);
                $breached = $probability !== null
                    && $probability >= ((float) $config->probability_threshold / 100);
                $reportedScore = $p50 ?? $peakScore;
            } else {
                $probability = null;
                $breached = $peakScore !== null
                    && $this->breachesFixedThreshold($peakScore, $config->comparison_operator, (float) $config->threshold_value);
                $reportedScore = $peakScore;
            }

            if (! $breached || $targetPassed) {
                $openAlert?->resolve();

                continue;
            }

            if ($openAlert !== null) {
                // The forecast still breaches — follow it, don't re-notify.
                $openAlert->update([
                    'score_at_trigger' => $reportedScore,
                    'forecast_target_date' => $peakDate,
                    'forecast_lead_days' => $leadDays,
                    'forecast_probability' => $probability,
                ]);

                continue;
            }

            $alert = Alert::query()->create([
                'threshold_config_id' => $config->threshold_config_id,
                'region_id' => $regionId,
                'index_id' => $indexId,
                'signal_type_id' => null,
                'score_at_trigger' => $reportedScore,
                'threshold_value' => $config->threshold_value,
                'status' => 'OPEN',
                'is_forecast' => true,
                'forecast_target_date' => $peakDate,
                'forecast_lead_days' => $leadDays,
                'forecast_probability' => $probability,
                'triggered_at' => now(),
            ]);

            $config->user->notify(new ThresholdBreachedNotification($alert));
        }
    }

    public function evaluateForSignal(int $signalTypeId, int $regionId, string $periodStart, float $value): void
    {
        $configs = $this->applicableConfigs('signal_type_id', $signalTypeId, $regionId);

        foreach ($configs as $config) {
            $history = fn () => RegionSignal::query()
                ->where('signal_type_id', $signalTypeId)
                ->where('region_id', $regionId)
                ->where('period_start', '<', $periodStart)
                ->orderByDesc('period_start')
                ->limit(self::ANOMALY_WINDOW)
                ->pluck('value')
                ->map(fn ($v) => (float) $v);

            $this->evaluateConfig($config, $regionId, indexId: null, signalTypeId: $signalTypeId, value: $value, history: $history);
        }
    }

    /**
     * @return Collection<int, ThresholdConfig>
     */
    private function applicableConfigs(string $targetColumn, int $targetId, int $regionId): Collection
    {
        $subscribedUserIds = UserRegionSubscription::query()
            ->where('region_id', $regionId)
            ->pluck('user_id');

        return ThresholdConfig::query()
            ->where('active', true)
            ->where($targetColumn, $targetId)
            ->where(function ($q) use ($regionId, $subscribedUserIds) {
                // A threshold scoped to this region directly, or scoped to "every region I
                // follow" (region_id null) for a user who actually follows this region.
                $q->where('region_id', $regionId)
                    ->orWhere(fn ($q2) => $q2->whereNull('region_id')->whereIn('user_id', $subscribedUserIds));
            })
            ->get();
    }

    private function evaluateConfig(
        ThresholdConfig $config,
        int $regionId,
        ?int $indexId,
        ?int $signalTypeId,
        float $value,
        \Closure $history,
    ): void {
        $breached = $config->isAnomalyType()
            ? $this->breachesAnomaly($value, $history(), (float) ($config->anomaly_stddev_multiplier ?? 2.0))
            : $this->breachesFixedThreshold($value, $config->comparison_operator, (float) $config->threshold_value);

        if (! $breached) {
            return;
        }

        $alreadyOpen = Alert::query()
            ->where('threshold_config_id', $config->threshold_config_id)
            ->where('status', 'OPEN')
            ->exists();

        if ($alreadyOpen) {
            return;
        }

        $alert = Alert::query()->create([
            'threshold_config_id' => $config->threshold_config_id,
            'region_id' => $regionId,
            'index_id' => $indexId,
            'signal_type_id' => $signalTypeId,
            'score_at_trigger' => $value,
            'threshold_value' => $config->threshold_value,
            'status' => 'OPEN',
            'triggered_at' => now(),
        ]);

        $config->user->notify(new ThresholdBreachedNotification($alert));
    }

    private function breachesFixedThreshold(float $value, ?string $operator, float $threshold): bool
    {
        return match ($operator) {
            '>' => $value > $threshold,
            '<' => $value < $threshold,
            '>=' => $value >= $threshold,
            default => false,
        };
    }

    /**
     * @param  Collection<int, float>  $history
     */
    private function breachesAnomaly(float $value, Collection $history, float $multiplier): bool
    {
        if ($history->count() < 3) {
            return false;
        }

        $mean = $history->avg();
        $variance = $history->reduce(fn ($carry, $v) => $carry + ($v - $mean) ** 2, 0.0) / $history->count();
        $stddev = sqrt($variance);

        if ($stddev <= 0.0) {
            return false;
        }

        return $value > $mean + ($multiplier * $stddev);
    }
}
