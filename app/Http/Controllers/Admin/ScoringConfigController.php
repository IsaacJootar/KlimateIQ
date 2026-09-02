<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RegionScoringConfig;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Support\CalibrationStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Edits the system-wide (region_id = null) defaults only — a region can still override any
 * of this with its own row at the database level, but that's not exposed here. No admin UI
 * for per-region overrides exists yet (I will do that later); this covers the common case every index actually uses.
 */
class ScoringConfigController extends Controller
{
    public function index(Request $request): View
    {
        $indices = ScoringIndex::query()->orderBy('name')->get();
        $index = $indices->firstWhere('index_id', $request->query('index')) ?? $indices->first();

        $configs = RegionScoringConfig::query()
            ->with('signalType')
            ->where('index_id', $index->index_id)
            ->whereNull('region_id')
            ->get()
            ->sortBy(fn ($c) => $c->signalType->name);

        $calibration = ScoringCalibrationParameter::query()
            ->where('index_id', $index->index_id)
            ->whereNull('region_id')
            ->get()
            ->groupBy(fn ($p) => str($p->parameter_key)->beforeLast('_')->value());

        return view('admin.scoring.index', [
            'indices' => $indices,
            'index' => $index,
            'configs' => $configs,
            'calibration' => $calibration,
        ]);
    }

    public function update(Request $request, ScoringIndex $index): RedirectResponse
    {
        $validated = $request->validate([
            'weight' => ['required', 'array'],
            'weight.*' => ['required', 'numeric', 'min:0', 'max:10'],
            'enabled' => ['array'],
            'enabled.*' => ['in:1'],
            'calibration_min' => ['required', 'array'],
            'calibration_min.*' => ['required', 'numeric'],
            'calibration_max' => ['required', 'array'],
            'calibration_max.*' => ['required', 'numeric', 'gt:0'],
        ]);

        // A hand edit is a deliberate human choice — no longer a shipped placeholder. It doesn't
        // downgrade a value that's already reference-derived or validated.
        $tunedStatus = fn (?CalibrationStatus $current) => in_array(
            $current, [CalibrationStatus::ReferenceDerived, CalibrationStatus::OutcomeValidated, CalibrationStatus::Reference], true
        ) ? $current : CalibrationStatus::AdminTuned;

        foreach ($validated['weight'] as $signalTypeId => $weight) {
            $config = RegionScoringConfig::query()
                ->where('index_id', $index->index_id)
                ->where('signal_type_id', $signalTypeId)
                ->whereNull('region_id')
                ->first();

            $config?->update([
                'weight' => $weight,
                'enabled' => isset($validated['enabled'][$signalTypeId]),
                'calibration_status' => $tunedStatus($config->calibration_status),
            ]);
        }

        foreach ($validated['calibration_min'] as $signalCode => $min) {
            $max = $validated['calibration_max'][$signalCode];

            abort_if($max <= $min, 422, "{$signalCode}: max must be greater than min.");

            foreach (['MIN' => $min, 'MAX' => $max] as $suffix => $value) {
                $param = ScoringCalibrationParameter::query()->firstOrNew(
                    ['index_id' => $index->index_id, 'region_id' => null, 'parameter_key' => "{$signalCode}_{$suffix}"]
                );
                $param->parameter_value = $value;
                $param->calibration_status = $tunedStatus($param->calibration_status);
                $param->save();
            }
        }

        return back()->with('status', "{$index->name} scoring configuration saved. Takes effect on the next score calculation.");
    }
}
