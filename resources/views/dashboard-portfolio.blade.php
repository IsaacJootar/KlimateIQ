<x-app-layout title="Dashboard">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Dashboard') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            <a href="{{ route('portfolios.show', $portfolio) }}" class="font-medium text-slate-700 dark:text-slate-200 hover:text-primary hover:underline">{{ $portfolio->name }}</a>
            <span class="text-gray-300 dark:text-gray-600">&middot;</span> {{ $rows->count() }} {{ $rows->count() === 1 ? 'location' : 'locations' }}
            @if ($otherPortfoliosCount > 0)
                <span class="text-gray-300 dark:text-gray-600">&middot;</span>
                <a href="{{ route('portfolios.index') }}" class="link-nav">{{ $otherPortfoliosCount }} other {{ $otherPortfoliosCount === 1 ? 'portfolio' : 'portfolios' }}</a>
            @endif
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if ($valueSummary->isNotEmpty())
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    @foreach ($valueSummary as $summary)
                        <a href="{{ route('portfolios.show', $portfolio) }}" class="metric-card metric-teal">
                            <div class="metric-card-label">Protected{{ $valueSummary->count() > 1 ? " ({$summary['currency']})" : '' }}</div>
                            <div class="metric-card-value">{{ $summary['symbol'] }}{{ number_format($summary['total']) }}</div>
                            <div class="metric-card-sub">total value under watch</div>
                        </a>
                        <a href="{{ route('portfolios.show', $portfolio) }}" class="metric-card metric-red">
                            <div class="metric-card-label">At Risk{{ $valueSummary->count() > 1 ? " ({$summary['currency']})" : '' }}</div>
                            <div class="metric-card-value">{{ $summary['symbol'] }}{{ number_format($summary['atRisk']) }}</div>
                            <div class="metric-card-sub">in red or amber locations</div>
                        </a>
                    @endforeach
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="{{ route('portfolios.show', $portfolio) }}" class="metric-card metric-red">
                    <div class="metric-card-label">Red</div>
                    <div class="metric-card-value"><x-count-up :value="$redCount" /></div>
                    <div class="metric-card-sub">locations at highest risk</div>
                </a>
                <a href="{{ route('portfolios.show', $portfolio) }}" class="metric-card metric-amber">
                    <div class="metric-card-label">Amber</div>
                    <div class="metric-card-value"><x-count-up :value="$amberCount" /></div>
                    <div class="metric-card-sub">locations elevated</div>
                </a>
                <a href="{{ route('portfolios.show', $portfolio) }}" class="metric-card metric-slate">
                    <div class="metric-card-label">Unscored</div>
                    <div class="metric-card-value"><x-count-up :value="$unscoredCount" /></div>
                    <div class="metric-card-sub">no data yet</div>
                </a>
                <a href="{{ route('alerts.index') }}" class="metric-card metric-teal">
                    <div class="metric-card-label">Open Alerts</div>
                    <div class="metric-card-value"><x-count-up :value="$openAlertsCount" /></div>
                    <div class="metric-card-sub">need your attention</div>
                </a>
            </div>

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">{{ $portfolio->name }} — ranked by risk</h3>
                    <a href="{{ route('portfolios.show', $portfolio) }}" class="link-nav text-xs">Manage portfolio &rarr;</a>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($rows as $i => $row)
                        <li class="flex items-center gap-4 px-5 py-3">
                            <span class="text-sm font-semibold text-slate-400 w-4">{{ $i + 1 }}</span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ $row['display_name'] }}</p>
                                @if ($row['approximated'])
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">≈ nearest LGA: {{ $row['nearest_region_name'] }} ({{ $row['distance_km'] }} km away)</p>
                                @elseif (! $row['scorable'])
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ $row['reason'] }}</p>
                                @elseif ($row['worst_index_name'])
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Worst: {{ $row['worst_index_name'] }}</p>
                                @endif
                            </div>
                            @if ($row['worst_score'] !== null)
                                <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $row['worst_score'] }}</span>
                                <span class="risk-badge risk-badge-{{ $row['worst_band'] }}">{{ $row['worst_band'] }}</span>
                            @else
                                <span class="risk-badge risk-badge-none">no data</span>
                            @endif
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-gray-500">
                            No locations in this portfolio yet.
                            <a href="{{ route('portfolios.show', $portfolio) }}" class="link-nav">Add one &rarr;</a>
                        </li>
                    @endforelse
                </ul>
            </div>

        </div>
    </div>
</x-app-layout>
