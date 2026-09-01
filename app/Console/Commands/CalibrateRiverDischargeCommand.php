<?php

namespace App\Console\Commands;

use App\Models\RegionSignal;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use Illuminate\Console\Command;

/**
 * Derives per-region RIVER_DISCHARGE bounds for the Riverine Flood Forecast index from each
 * LGA's own observed discharge history (BUILD_PLAN.md T4). Rivers span three orders of
 * magnitude of flow — a system-wide bound pegs every big-river LGA at 100 — so the index only
 * discriminates once it is measured against each reach's normal range.
 *
 * A rough, honest first calibration: the observed range stretched to leave the top of the
 * observed record roughly at the amber line. Real return periods are a hydrology exercise.
 * Idempotent, and it never overwrites a bound an admin has tuned by hand.
 */
class CalibrateRiverDischargeCommand extends Command
{
    protected $signature = 'calibrate:river-discharge {--min-weeks=4 : Skip a region with fewer than this many observed readings}';

    protected $description = 'Set per-region river-discharge calibration bounds from observed history.';

    private const AUTO_NOTE = 'Auto-derived from this LGA\'s observed discharge history — re-run of calibrate:river-discharge.';

    public function handle(): int
    {
        $index = ScoringIndex::query()->where('code', 'RIVERINE_FLOOD_FORECAST')->first();
        $dischargeId = SignalType::query()->where('code', 'RIVER_DISCHARGE')->value('signal_type_id');

        if ($index === null || $dischargeId === null) {
            $this->warn('Riverine Flood Forecast index or RIVER_DISCHARGE signal type not seeded yet.');

            return self::SUCCESS;
        }

        $minWeeks = (int) $this->option('min-weeks');
        $calibrated = 0;

        $byRegion = RegionSignal::query()
            ->where('signal_type_id', $dischargeId)
            ->get(['region_id', 'value'])
            ->groupBy('region_id');

        foreach ($byRegion as $regionId => $rows) {
            if ($rows->count() < $minWeeks) {
                continue;
            }

            $values = $rows->pluck('value')->map(fn ($v) => (float) $v);
            $observedMax = $values->max();
            $observedMin = $values->min();

            // Headroom above the observed record so a genuine flood clears the red line;
            // a little below the observed floor so a dry spell reads green.
            $max = round($observedMax * 1.4, 2);
            $min = round(max(0, $observedMin * 0.8), 2);

            foreach (['MIN' => $min, 'MAX' => $max] as $suffix => $value) {
                $existing = ScoringCalibrationParameter::query()
                    ->where('index_id', $index->index_id)
                    ->where('region_id', $regionId)
                    ->where('parameter_key', "RIVER_DISCHARGE_{$suffix}")
                    ->first();

                // Don't clobber a hand-tuned bound (one whose note isn't ours).
                if ($existing !== null && $existing->source_reference !== self::AUTO_NOTE) {
                    continue;
                }

                ScoringCalibrationParameter::query()->updateOrCreate(
                    ['index_id' => $index->index_id, 'region_id' => $regionId, 'parameter_key' => "RIVER_DISCHARGE_{$suffix}"],
                    ['parameter_value' => $value, 'source_reference' => self::AUTO_NOTE],
                );
            }

            $calibrated++;
        }

        $this->info("Calibrated river-discharge bounds for {$calibrated} regions.");

        return self::SUCCESS;
    }
}
