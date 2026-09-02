<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Models\ThresholdConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ThresholdConfigController extends Controller
{
    public function index(): View
    {
        $thresholds = ThresholdConfig::query()
            ->where('user_id', Auth::id())
            ->with(['region', 'index', 'signalType'])
            ->orderByDesc('created_at')
            ->get();

        // The region picker is scoped to the user's own coverage — thresholds only make sense on
        // regions they watch. With no coverage set, fall back to every active region (the ones
        // that actually have data to threshold on), not all 774 seeded LGAs.
        $regionIds = Auth::user()->regionSubscriptions()->pluck('region_id');
        $regions = Region::query()
            ->when(
                $regionIds->isNotEmpty(),
                fn ($q) => $q->whereIn('region_id', $regionIds),
                fn ($q) => $q->active(),
            )
            ->orderBy('name')
            ->get();

        return view('thresholds.index', [
            'thresholds' => $thresholds,
            'regions' => $regions,
            'hasRegionCoverage' => $regionIds->isNotEmpty(),
            'indices' => ScoringIndex::all(),
            'signalTypes' => SignalType::all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'region_id' => ['required', 'exists:regions,region_id'],
            'target_type' => ['required', 'in:index,signal'],
            'index_id' => ['nullable', 'required_if:target_type,index', 'exists:indices,index_id'],
            'signal_type_id' => ['nullable', 'required_if:target_type,signal', 'exists:signal_types,signal_type_id'],
            'alert_type' => ['required', 'in:fixed_threshold,anomaly'],
            'comparison_operator' => ['nullable', 'required_if:alert_type,fixed_threshold', 'in:>,<,>='],
            'threshold_value' => ['nullable', 'required_if:alert_type,fixed_threshold', 'numeric'],
            'anomaly_stddev_multiplier' => ['nullable', 'required_if:alert_type,anomaly', 'numeric', 'min:0.5', 'max:6'],
        ]);

        ThresholdConfig::query()->create([
            'user_id' => Auth::id(),
            'region_id' => $validated['region_id'],
            'index_id' => $validated['target_type'] === 'index' ? $validated['index_id'] : null,
            'signal_type_id' => $validated['target_type'] === 'signal' ? $validated['signal_type_id'] : null,
            'alert_type' => $validated['alert_type'],
            'comparison_operator' => $validated['comparison_operator'] ?? null,
            'threshold_value' => $validated['threshold_value'] ?? null,
            'anomaly_stddev_multiplier' => $validated['anomaly_stddev_multiplier'] ?? null,
            'active' => true,
        ]);

        return back()->with('status', 'Threshold created.');
    }

    public function toggle(ThresholdConfig $threshold): RedirectResponse
    {
        abort_unless($threshold->user_id === Auth::id(), 403);
        $threshold->update(['active' => ! $threshold->active]);

        return back()->with('status', $threshold->active ? 'Threshold activated.' : 'Threshold paused.');
    }

    public function destroy(ThresholdConfig $threshold): RedirectResponse
    {
        abort_unless($threshold->user_id === Auth::id(), 403);
        $threshold->delete();

        return back()->with('status', 'Threshold deleted.');
    }
}
