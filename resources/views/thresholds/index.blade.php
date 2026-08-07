<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Thresholds') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="p-4 rounded-lg bg-green-50 dark:bg-green-900 text-green-700 dark:text-green-200 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <div class="section-card p-6">
                <h3 class="font-semibold text-gray-800 dark:text-gray-200 mb-4">New threshold</h3>
                <form method="POST" action="{{ route('thresholds.store') }}" x-data="{ targetType: 'index', alertType: 'fixed_threshold' }" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @csrf

                    <div>
                        <label class="block text-sm font-medium mb-1">Region</label>
                        <select name="region_id" required class="w-full rounded-lg">
                            @foreach ($regions as $region)
                                <option value="{{ $region->region_id }}">{{ $region->name }}, {{ $region->state }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">Watch</label>
                        <select name="target_type" x-model="targetType" class="w-full rounded-lg">
                            <option value="index">A named index (composite)</option>
                            <option value="signal">A single signal</option>
                        </select>
                    </div>

                    <div x-show="targetType === 'index'">
                        <label class="block text-sm font-medium mb-1">Index</label>
                        <select name="index_id" class="w-full rounded-lg">
                            @foreach ($indices as $idx)
                                <option value="{{ $idx->index_id }}">{{ $idx->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div x-show="targetType === 'signal'">
                        <label class="block text-sm font-medium mb-1">Signal</label>
                        <select name="signal_type_id" class="w-full rounded-lg">
                            @foreach ($signalTypes as $signalType)
                                <option value="{{ $signalType->signal_type_id }}">{{ $signalType->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">Alert type</label>
                        <select name="alert_type" x-model="alertType" class="w-full rounded-lg">
                            <option value="fixed_threshold">Fixed threshold</option>
                            <option value="anomaly">Anomaly vs. this region's own baseline</option>
                        </select>
                    </div>

                    <div x-show="alertType === 'fixed_threshold'" class="flex gap-2">
                        <select name="comparison_operator" class="rounded-lg">
                            <option value=">">&gt;</option>
                            <option value="<">&lt;</option>
                            <option value=">=">&ge;</option>
                        </select>
                        <input type="number" step="0.01" name="threshold_value" placeholder="Value" class="flex-1 rounded-lg">
                    </div>

                    <div x-show="alertType === 'anomaly'">
                        <label class="block text-sm font-medium mb-1">Std. deviations from baseline</label>
                        <input type="number" step="0.1" min="0.5" max="6" name="anomaly_stddev_multiplier" value="2" class="w-full rounded-lg">
                    </div>

                    <div class="sm:col-span-2">
                        <button type="submit" class="btn-primary">Create threshold</button>
                    </div>
                </form>
            </div>

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Your thresholds</h3>
                </div>
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3">Region</th>
                            <th class="px-4 py-3">Watching</th>
                            <th class="px-4 py-3">Condition</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($thresholds as $threshold)
                            <tr>
                                <td class="px-4 py-3 text-sm">{{ $threshold->region->name }}</td>
                                <td class="px-4 py-3 text-sm">{{ $threshold->index?->name ?? $threshold->signalType?->name }}</td>
                                <td class="px-4 py-3 text-sm">
                                    @if ($threshold->isAnomalyType())
                                        &gt; {{ $threshold->anomaly_stddev_multiplier }}&sigma; from baseline
                                    @else
                                        {{ $threshold->comparison_operator }} {{ $threshold->threshold_value }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="risk-badge {{ $threshold->active ? 'risk-badge-green' : 'risk-badge-none' }}">
                                        {{ $threshold->active ? 'active' : 'paused' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-right space-x-2">
                                    <form method="POST" action="{{ route('thresholds.toggle', $threshold) }}" class="inline">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="btn-secondary">{{ $threshold->active ? 'Pause' : 'Activate' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('thresholds.destroy', $threshold) }}" class="inline">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No thresholds yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
