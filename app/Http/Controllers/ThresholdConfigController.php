<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Models\ThresholdConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
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
            // Indices with a forecast lane — the only ones a probability rule can apply to.
            'forwardScorableIndexIds' => ScoringIndex::query()
                ->forwardScorable($this->forecastSignalCodes())->pluck('index_id'),
            'signalTypes' => SignalType::all(),
        ]);
    }

    /** signal_types.code that have a forecast/ensemble source (drives forwardScorable). */
    private function forecastSignalCodes(): array
    {
        return collect(config('ingestion.forecast_sources', []))
            ->map(fn ($class) => app($class)->signalTypeCode())
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'region_id' => ['required', 'exists:regions,region_id'],
            'target_type' => ['required', 'in:index,signal'],
            'index_id' => ['nullable', 'required_if:target_type,index', 'exists:indices,index_id'],
            'signal_type_id' => ['nullable', 'required_if:target_type,signal', 'exists:signal_types,signal_type_id'],
            'alert_type' => ['required', 'in:fixed_threshold,anomaly,forecast_probability'],
            'comparison_operator' => ['nullable', 'required_if:alert_type,fixed_threshold', 'in:>,<,>='],
            'threshold_value' => ['nullable', 'required_if:alert_type,fixed_threshold', 'numeric'],
            'prob_threshold_value' => ['nullable', 'required_if:alert_type,forecast_probability', 'numeric'],
            'anomaly_stddev_multiplier' => ['nullable', 'required_if:alert_type,anomaly', 'numeric', 'min:0.5', 'max:6'],
            'probability_threshold' => ['nullable', 'required_if:alert_type,forecast_probability', 'numeric', 'min:1', 'max:99'],
        ]);

        $indexLevel = $validated['alert_type'] === 'forecast_probability'
            ? ($validated['prob_threshold_value'] ?? null)
            : ($validated['threshold_value'] ?? null);

        // A probability rule only makes sense on an index that has a forecast lane.
        if ($validated['alert_type'] === 'forecast_probability') {
            $forwardScorable = ScoringIndex::query()
                ->forwardScorable($this->forecastSignalCodes())
                ->whereKey($validated['index_id'])->exists();

            if ($validated['target_type'] !== 'index' || ! $forwardScorable) {
                return back()->withInput()
                    ->withErrors(['alert_type' => 'A probability rule needs an index with a forecast — pick one of the forecast-capable indices.']);
            }
        }

        ThresholdConfig::query()->create([
            'user_id' => Auth::id(),
            'region_id' => $validated['region_id'],
            'index_id' => $validated['target_type'] === 'index' ? $validated['index_id'] : null,
            'signal_type_id' => $validated['target_type'] === 'signal' ? $validated['signal_type_id'] : null,
            'alert_type' => $validated['alert_type'],
            'comparison_operator' => $validated['alert_type'] === 'forecast_probability' ? '>=' : ($validated['comparison_operator'] ?? null),
            'threshold_value' => $indexLevel,
            'anomaly_stddev_multiplier' => $validated['anomaly_stddev_multiplier'] ?? null,
            'probability_threshold' => $validated['probability_threshold'] ?? null,
            'watch_forecast' => $validated['alert_type'] === 'forecast_probability',
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
