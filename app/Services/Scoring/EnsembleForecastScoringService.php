<?php

namespace App\Services\Scoring;

use App\Models\Region;
use App\Models\RegionForecastSignal;
use App\Models\RegionScoringConfig;
use App\Models\RegionSignal;
use App\Models\ScoringIndex;
use App\Support\RiskBand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Scores every ensemble member of a forward index through the same formula the control run uses
 * (ForecastScoringStrategy::scoreDailySeries), then reduces the per-member peak scores to a
 * distribution: p10 / p50 / p90, and the probability the peak reaches a reference level within
 * the horizon (BUILD_PLAN.md T5).
 *
 * A member's series differs from the control's only in the forecastable drivers — a weighted
 * signal with no series for that member (a signal with no ensemble at all, or one whose model
 * has fewer members) falls back to the region's latest observed reading held flat, exactly as
 * the control path does. So the spread of member scores is the forecast uncertainty in those
 * drivers, mapped through the unchanged index weights and calibration.
 */
class EnsembleForecastScoringService
{
    /** Fewer resolved members than this and we don't claim a distribution — the control score stands alone. */
    private const MIN_MEMBERS = 5;

    public function __construct(private readonly ForecastScoringStrategy $strategy) {}

    public function distribution(ScoringIndex $index, Region $region, Carbon $issuedAt): ?EnsembleScoreResult
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

        if ($configs->isEmpty()) {
            return null;
        }

        // signal_type_id => member => (date => value), forward of the issue date.
        $bySignalMember = RegionForecastSignal::query()
            ->where('region_id', $region->region_id)
            ->where('member', '!=', 'control')
            ->whereIn('signal_type_id', $configs->keys())
            ->whereDate('target_date', '>=', $issuedAt->toDateString())
            ->orderBy('target_date')
            ->get()
            ->groupBy('signal_type_id')
            ->map(fn ($rows) => $rows->groupBy('member')
                ->map(fn ($r) => $r->mapWithKeys(fn ($x) => [$x->target_date->toDateString() => (float) $x->value])));

        if ($bySignalMember->isEmpty()) {
            return null;
        }

        // Every weighted signal's latest observed value — the flat fallback for any signal a
        // given member has no series for (same rule as the control path).
        $observedFallback = RegionSignal::query()
            ->where('region_id', $region->region_id)
            ->whereIn('signal_type_id', $configs->keys())
            ->orderByDesc('period_start')
            ->get()
            ->groupBy('signal_type_id')
            ->map(fn ($rows) => (float) $rows->first()->value);

        $memberIds = $bySignalMember->flatMap(fn ($m) => $m->keys())->unique()->values();

        $peaks = [];
        $perDay = []; // date => [lead_days, list<score>]

        foreach ($memberIds as $memberId) {
            $seriesBySignalId = $configs->keys()->mapWithKeys(function ($signalId) use ($bySignalMember, $memberId) {
                $series = $bySignalMember->get($signalId)?->get($memberId);

                return $series !== null ? [$signalId => $series] : [];
            });

            if ($seriesBySignalId->isEmpty()) {
                continue;
            }

            $daily = $this->strategy->scoreDailySeries($index, $region, $issuedAt, $configs, $seriesBySignalId, $observedFallback);
            if ($daily === []) {
                continue;
            }

            $peaks[] = (float) collect($daily)->sortByDesc('score')->first()['score'];

            foreach ($daily as $d) {
                $perDay[$d['date']] ??= ['lead_days' => $d['lead_days'], 'scores' => []];
                $perDay[$d['date']]['scores'][] = (float) $d['score'];
            }
        }

        if (count($peaks) < self::MIN_MEMBERS) {
            return null;
        }

        sort($peaks);
        $reference = 67.0; // the red-band cutoff (App\Support\RiskBand)

        ksort($perDay);
        $memberDaily = [];
        foreach ($perDay as $date => $bucket) {
            $sorted = $bucket['scores'];
            sort($sorted);
            $memberDaily[] = [
                'date' => $date,
                'lead_days' => $bucket['lead_days'],
                'p10' => round($this->percentile($sorted, 10), 2),
                'p50' => round($this->percentile($sorted, 50), 2),
                'p90' => round($this->percentile($sorted, 90), 2),
            ];
        }

        return new EnsembleScoreResult(
            p10: round($this->percentile($peaks, 10), 2),
            p50: round($this->percentile($peaks, 50), 2),
            p90: round($this->percentile($peaks, 90), 2),
            exceedanceProbability: round(count(array_filter($peaks, fn ($p) => $p >= $reference)) / count($peaks), 4),
            exceedanceReference: $reference,
            memberCount: count($peaks),
            memberPeaks: array_map(fn ($p) => round($p, 2), $peaks),
            memberDaily: $memberDaily,
        );
    }

    /**
     * P(member peak ≥ $level) read straight off the empirical distribution — used by the
     * probability-threshold alert rule (BUILD_PLAN.md T5 M3).
     *
     * @param  list<float>  $memberPeaks
     */
    public static function exceedanceShare(array $memberPeaks, float $level): float
    {
        if ($memberPeaks === []) {
            return 0.0;
        }

        return count(array_filter($memberPeaks, fn ($p) => $p >= $level)) / count($memberPeaks);
    }

    /**
     * Linear-interpolated percentile of a pre-sorted ascending array.
     *
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return $sorted[0];
        }

        $rank = ($p / 100) * ($n - 1);
        $lo = (int) floor($rank);
        $hi = (int) ceil($rank);

        return $sorted[$lo] + ($rank - $lo) * ($sorted[$hi] - $sorted[$lo]);
    }
}
