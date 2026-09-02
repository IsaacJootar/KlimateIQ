<x-app-layout title="Index & Scoring Config">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Index & Scoring Config') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            System-wide defaults for how each index is calculated. A region can still override any of this at the
            database level, but every region uses these defaults unless it does.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="flex flex-wrap gap-2">
                @foreach ($indices as $idx)
                    <a href="{{ route('admin.scoring.index', ['index' => $idx->index_id]) }}"
                       class="pill-tab {{ $idx->index_id === $index->index_id ? 'pill-tab-active' : '' }}">
                        {{ $idx->name }}
                    </a>
                @endforeach
            </div>

            <form method="POST" action="{{ route('admin.scoring.update', $index) }}">
                @csrf
                @method('PUT')

                <div class="section-card p-6">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Signal weights</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
                        How much each signal counts toward {{ $index->name }}. Weights are relative to each other —
                        they don't need to add up to any particular total. Disabling a signal excludes it from the
                        calculation entirely rather than counting it as zero.
                    </p>

                    <div class="space-y-4">
                        @foreach ($configs as $config)
                            <div class="flex items-center gap-4 pb-4 border-b border-gray-100 dark:border-gray-700 last:border-0 last:pb-0">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">
                                        {{ $config->signalType->name }}
                                        <x-calibration-chip :status="$config->calibration_status" class="ml-1.5 align-middle" />
                                    </p>
                                    <p class="text-xs text-slate-400 dark:text-slate-500">
                                        {{ $config->signalType->code }} &middot; {{ $config->signalType->source }}
                                    </p>
                                </div>
                                <div class="w-28">
                                    <x-text-input type="number" step="0.01" min="0" max="10"
                                        name="weight[{{ $config->signal_type_id }}]" value="{{ old('weight.'.$config->signal_type_id, $config->weight) }}" />
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                                    <input type="checkbox" name="enabled[{{ $config->signal_type_id }}]" value="1"
                                           @checked(old('enabled.'.$config->signal_type_id, $config->enabled))
                                           class="sr-only peer">
                                    <div class="w-11 h-6 bg-slate-200 dark:bg-slate-600 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-gano-500 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-gano-700"></div>
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="section-card p-6 mt-6">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Calibration bounds</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
                        The realistic min/max range each signal is normalized against before weighting. These are
                        seeded for every signal, including ones not used by {{ $index->name }} above — that's fine,
                        an unused signal's bounds simply aren't consulted for this index.
                    </p>
                    <p class="text-xs text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 mb-6">
                        Each bound carries a status: <strong>uncalibrated placeholder</strong> (a climatologically
                        plausible guess), <strong>from a cited reference</strong> (PM / ozone / NO₂ / dust — WHO &amp;
                        EPA points), <strong>derived from real data</strong> (river discharge — GloFAS reanalysis
                        return periods), <strong>set by an admin</strong>, or <strong>validated against outcomes</strong>
                        (none yet — that's the T8 workstream). See <code>docs/MODEL.md</code>.
                    </p>

                    <div class="space-y-4">
                        @foreach ($calibration as $signalCode => $params)
                            @php
                                $min = $params->firstWhere('parameter_key', "{$signalCode}_MIN");
                                $max = $params->firstWhere('parameter_key', "{$signalCode}_MAX");
                            @endphp
                            <div class="flex items-center gap-4 pb-4 border-b border-gray-100 dark:border-gray-700 last:border-0 last:pb-0">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">
                                        {{ $signalCode }}
                                        <x-calibration-chip :status="($min ?? $max)?->calibration_status" class="ml-1.5 align-middle" />
                                    </p>
                                    @if (($min ?? $max)?->source_reference)
                                        <p class="text-xs text-slate-400 dark:text-slate-500">{{ ($min ?? $max)->source_reference }}</p>
                                    @endif
                                </div>
                                <div class="w-28">
                                    <x-input-label class="text-xs">Min</x-input-label>
                                    <x-text-input type="number" step="0.01"
                                        name="calibration_min[{{ $signalCode }}]" value="{{ old('calibration_min.'.$signalCode, $min?->parameter_value) }}" />
                                </div>
                                <div class="w-28">
                                    <x-input-label class="text-xs">Max</x-input-label>
                                    <x-text-input type="number" step="0.01"
                                        name="calibration_max[{{ $signalCode }}]" value="{{ old('calibration_max.'.$signalCode, $max?->parameter_value) }}" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <x-loading-button class="btn-primary w-full sm:w-auto mt-6" loading-text="Saving…">Save {{ $index->name }} configuration</x-loading-button>
            </form>
        </div>
    </div>
</x-app-layout>
