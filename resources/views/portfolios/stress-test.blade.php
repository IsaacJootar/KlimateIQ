<x-app-layout :title="$portfolio->name.' — Stress Test'">
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-primary">Scenario Testing</p>
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ $portfolio->name }}
                </h2>
            </div>
            <a href="{{ route('portfolios.show', $portfolio) }}" class="link-nav">&larr; Back to {{ $portfolio->name }}</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="flex gap-2.5 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 px-3.5 py-2.5">
                <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>
                </svg>
                <p class="text-sm text-amber-900 dark:text-amber-100">
                    Illustrative only — a stated stress assumption applied to today's real scores, not a calibrated
                    forecast. Use it to reason about "how would this shift," not to predict what will happen.
                </p>
            </div>

            <div class="section-card p-5">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Pick a scenario</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
                    Each preset raises every asset's current score by a stated percentage, capped at 100 — the same
                    0&ndash;100 scale and 34/67 bands used everywhere else in the app.
                </p>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('portfolios.stress-test', $portfolio) }}"
                       class="btn-secondary {{ $selected === null ? '!bg-slate-800 !text-white dark:!bg-slate-200 dark:!text-slate-900' : '' }}">
                        Baseline (no stress)
                    </a>
                    @foreach ($presets as $key => $p)
                        <a href="{{ route('portfolios.stress-test', [$portfolio, 'preset' => $key]) }}"
                           class="btn-secondary {{ $selected === $key ? '!bg-slate-800 !text-white dark:!bg-slate-200 dark:!text-slate-900' : '' }}">
                            {{ $p['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>

            @if ($preset !== null)
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="metric-card metric-red">
                        <div class="metric-card-label">Red Locations</div>
                        <div class="metric-card-value">{{ $baselineRedCount }} &rarr; {{ $stressedRedCount }}</div>
                        <div class="metric-card-sub">under {{ $preset['label'] }}</div>
                    </div>
                    @foreach ($stressedSummary as $i => $summary)
                        <div class="metric-card metric-red">
                            <div class="metric-card-label">At Risk{{ $stressedSummary->count() > 1 ? " ({$summary['currency']})" : '' }}</div>
                            <div class="metric-card-value">
                                {{ $summary['symbol'] }}{{ number_format($baselineSummary[$i]['atRisk'] ?? 0) }}
                                &rarr;
                                {{ $summary['symbol'] }}{{ number_format($summary['atRisk']) }}
                            </div>
                            <div class="metric-card-sub">under {{ $preset['label'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Assets</h3>
                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ $preset !== null ? 'current → under stress' : 'current' }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3">Location</th>
                                <th class="px-4 py-3">Value</th>
                                <th class="px-4 py-3">Score</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($rows as $row)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-200">{{ $row['display_name'] }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $row['location']->formattedValue() ?? '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        @if ($row['worst_score'] !== null)
                                            <span class="risk-badge risk-badge-{{ $row['worst_band'] }}">{{ round($row['worst_score']) }}</span>
                                        @else
                                            <span class="text-xs text-gray-400">No score yet</span>
                                        @endif
                                        @if ($preset !== null && $row['stressed_score'] !== null)
                                            <span class="mx-1 text-slate-400">&rarr;</span>
                                            <span class="risk-badge risk-badge-{{ $row['stressed_band'] }}">{{ round($row['stressed_score']) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-4 py-8 text-center text-sm text-gray-500">No locations yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
