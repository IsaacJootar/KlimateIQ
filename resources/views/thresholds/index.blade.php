<x-app-layout title="Thresholds">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Thresholds') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Get notified the moment a region crosses a number you care about — no need to check the dashboard yourself.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="section-card p-6">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Configure a threshold</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">Two decisions: what to watch, and when it should alert you.</p>

                <form method="POST" action="{{ route('thresholds.store') }}"
                      x-data="{
                          targetType: 'index',
                          alertType: 'fixed_threshold',
                          indexId: '',
                          forecastIndexIds: {{ Illuminate\Support\Js::from($indices->where('is_forecast', true)->pluck('index_id')->map('strval')->values()) }},
                          forwardScorableIndexIds: {{ Illuminate\Support\Js::from($forwardScorableIndexIds->map('strval')->values()) }},
                          get canProbability() { return this.targetType === 'index' && this.forwardScorableIndexIds.includes(this.indexId); },
                      }"
                      x-init="$watch('canProbability', v => { if (!v && alertType === 'forecast_probability') alertType = 'fixed_threshold'; })">
                    @csrf

                    <x-form-section title="What do you want to watch?"
                        description="Pick a region, then either a named index (the composite risk score) or one raw signal.">
                        <div>
                            <x-input-label for="region_id">Region</x-input-label>
                            <x-select-input id="region_id" name="region_id" required>
                                @forelse ($regions as $region)
                                    <option value="{{ $region->region_id }}">{{ $region->name }}, {{ $region->state }}</option>
                                @empty
                                    <option value="" disabled>No regions available yet</option>
                                @endforelse
                            </x-select-input>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                @if ($hasRegionCoverage)
                                    Your coverage regions. <a href="{{ route('coverage.edit') }}" class="link-nav">Change coverage</a> to add more.
                                @else
                                    Every active region. <a href="{{ route('coverage.edit') }}" class="link-nav">Set your coverage</a> to shorten this list.
                                @endif
                            </p>
                        </div>

                        <div>
                            <x-input-label for="target_type">Watch</x-input-label>
                            <x-select-input id="target_type" name="target_type" x-model="targetType">
                                <option value="index">A named index (composite risk score)</option>
                                <option value="signal">One raw signal</option>
                            </x-select-input>
                        </div>

                        <div x-show="targetType === 'index'" x-cloak>
                            <x-input-label for="index_id">Index</x-input-label>
                            <x-select-input id="index_id" name="index_id" x-model="indexId">
                                @foreach ($indices as $idx)
                                    <option value="{{ $idx->index_id }}">{{ $idx->name }}</option>
                                @endforeach
                            </x-select-input>
                            <p x-show="forecastIndexIds.includes(indexId)" x-cloak
                               class="mt-1.5 text-xs text-sky-700 dark:text-sky-300">
                                This is a forecast index — the alert fires on the forecast <em>peak</em>, clearly labelled
                                as a forecast, and clears itself when the forecast recedes or its date passes.
                            </p>
                        </div>

                        <div x-show="targetType === 'signal'" x-cloak>
                            <x-input-label for="signal_type_id">Signal</x-input-label>
                            <x-select-input id="signal_type_id" name="signal_type_id">
                                @foreach ($signalTypes as $signalType)
                                    <option value="{{ $signalType->signal_type_id }}">{{ $signalType->name }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                    </x-form-section>

                    <x-form-section title="When should it alert you?" last="true"
                        description="A fixed threshold fires when the value crosses a number you set. An anomaly alert compares against this region's own recent history instead. A probability alert (forecast indices) fires when the ensemble forecast makes a crossing likely enough.">
                        <div>
                            <x-input-label for="alert_type">Alert type</x-input-label>
                            <x-select-input id="alert_type" name="alert_type" x-model="alertType">
                                <option value="fixed_threshold">Fixed threshold</option>
                                <option value="anomaly">Anomaly vs. this region's own baseline</option>
                                <option value="forecast_probability" x-bind:disabled="!canProbability">
                                    Probability of a forecast crossing
                                </option>
                            </x-select-input>
                            <p x-show="!canProbability" x-cloak class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                The probability option needs a forecast-capable index (e.g. Riverine Flood Forecast, or Flood / Heat / Malaria / Drought).
                            </p>
                        </div>

                        {{-- Hidden blocks disable their inputs so only the active rule's fields submit. --}}
                        <div x-show="alertType === 'forecast_probability'" x-cloak class="space-y-1">
                            <x-input-label>Alert me when the ensemble forecast gives at least…</x-input-label>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-text-input type="number" step="1" min="1" max="99" name="probability_threshold" placeholder="60" class="!w-20"
                                    x-bind:disabled="alertType !== 'forecast_probability'" />
                                <span class="text-sm text-slate-500 dark:text-slate-400">% chance of the score reaching</span>
                                <x-text-input type="number" step="0.01" name="prob_threshold_value" placeholder="67" class="!w-20"
                                    x-bind:disabled="alertType !== 'forecast_probability'" />
                            </div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                …at some point within the 14-day forecast window. 67 is the red band, 34 amber. This is an ensemble probability, not a certainty.
                            </p>
                        </div>

                        <div x-show="alertType === 'fixed_threshold'" x-cloak>
                            <x-input-label>Condition</x-input-label>
                            <div class="flex gap-2">
                                <x-select-input name="comparison_operator" class="!w-28" x-bind:disabled="alertType !== 'fixed_threshold'">
                                    <option value=">">is above (&gt;)</option>
                                    <option value="<">is below (&lt;)</option>
                                    <option value=">=">is at least (&ge;)</option>
                                </x-select-input>
                                <x-text-input type="number" step="0.01" name="threshold_value" placeholder="e.g. 70" class="flex-1"
                                    x-bind:disabled="alertType !== 'fixed_threshold'" />
                            </div>
                        </div>

                        <div x-show="alertType === 'anomaly'" x-cloak>
                            <x-input-label for="anomaly_stddev_multiplier">Standard deviations from baseline</x-input-label>
                            <x-text-input id="anomaly_stddev_multiplier" type="number" step="0.1" min="0.5" max="6"
                                name="anomaly_stddev_multiplier" value="2" x-bind:disabled="alertType !== 'anomaly'" />
                        </div>
                    </x-form-section>

                    <x-loading-button class="btn-primary w-full sm:w-auto" loading-text="Saving…">Configure threshold</x-loading-button>
                </form>
            </div>

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Your thresholds</h3>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($thresholds as $threshold)
                        <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">
                                    {{ $threshold->index?->name ?? $threshold->signalType?->name }}
                                    <span class="font-normal text-slate-400">in</span>
                                    {{ $threshold->region->name }}
                                </p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    @if ($threshold->isAnomalyType())
                                        Alerts when it's more than {{ $threshold->anomaly_stddev_multiplier }}&sigma; from this region's own baseline
                                    @elseif ($threshold->isProbabilityType())
                                        Alerts when the ensemble forecast gives &ge; {{ (int) $threshold->probability_threshold }}% chance of reaching {{ $threshold->threshold_value }} within 14 days
                                    @else
                                        Alerts when the value {{ ['>' => 'is above', '<' => 'is below', '>=' => 'is at least'][$threshold->comparison_operator] ?? $threshold->comparison_operator }} {{ $threshold->threshold_value }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <span class="risk-badge {{ $threshold->active ? 'risk-badge-green' : 'risk-badge-none' }}">
                                    {{ $threshold->active ? 'active' : 'paused' }}
                                </span>
                                <form method="POST" action="{{ route('thresholds.toggle', $threshold) }}" class="inline">
                                    @csrf @method('PATCH')
                                    <x-loading-button class="btn-secondary" loading-text="{{ $threshold->active ? 'Pausing…' : 'Activating…' }}">{{ $threshold->active ? 'Pause' : 'Activate' }}</x-loading-button>
                                </form>
                                <form method="POST" action="{{ route('thresholds.destroy', $threshold) }}" class="inline">
                                    @csrf @method('DELETE')
                                    <x-loading-button class="btn-danger" loading-text="Deleting…">Delete</x-loading-button>
                                </form>
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-gray-500">No thresholds configured yet — set one up above.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
