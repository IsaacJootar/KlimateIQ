<x-app-layout title="Dashboard">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Dashboard') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            @if ($hasCoverage)
                Your workspace, at a glance
            @else
                Showing every region currently active on the platform — you haven't narrowed your regions yet
            @endif
            @if ($hasIndexCoverage)
                — configured for {{ $availableIndices->pluck('name')->join(', ', ', and ') }}.
            @else
                — no sectors picked, so every index is shown.
            @endif
            <a href="{{ route('coverage.edit') }}" class="link-nav">Edit workspace</a>
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if ($availableIndices->count() > 1)
                <div class="flex flex-wrap gap-2">
                    @foreach ($availableIndices as $idx)
                        <a href="{{ route('dashboard', ['index' => $idx->code]) }}"
                           class="pill-tab {{ $idx->index_id === $defaultIndex->index_id ? 'pill-tab-active' : '' }}">
                            {{ $idx->name }}
                        </a>
                    @endforeach
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="{{ route('regions.index') }}" class="metric-card metric-teal">
                    <div class="metric-card-label">{{ $hasCoverage ? 'Your Regions' : 'Active Regions' }}</div>
                    <div class="metric-card-value"><x-count-up :value="$regionsCount" /></div>
                    <div class="metric-card-sub">{{ $defaultIndex->name }}</div>
                </a>
                <a href="{{ route('regions.index') }}" class="metric-card metric-red">
                    <div class="metric-card-label">High Risk</div>
                    <div class="metric-card-value"><x-count-up :value="$highRiskCount" /></div>
                    <div class="metric-card-sub">score &ge; 67</div>
                </a>
                <a href="{{ route('alerts.index') }}" class="metric-card metric-amber">
                    <div class="metric-card-label">Open Alerts</div>
                    <div class="metric-card-value"><x-count-up :value="$openAlertsCount" /></div>
                    <div class="metric-card-sub">need your attention</div>
                </a>
                <a href="{{ route('thresholds.index') }}" class="metric-card metric-slate">
                    <div class="metric-card-label">Active Thresholds</div>
                    <div class="metric-card-value"><x-count-up :value="$activeThresholdsCount" /></div>
                    <div class="metric-card-sub">configured</div>
                </a>
            </div>

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Highest risk regions</h3>
                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ $defaultIndex->name }}</span>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($topRiskRegions as $i => $row)
                        <li class="flex items-center gap-4 px-5 py-3">
                            <span class="text-sm font-semibold text-slate-400 w-4">{{ $i + 1 }}</span>
                            <a href="{{ route('regions.show', ['region' => $row['region']->region_id, 'index' => $defaultIndex->code]) }}" class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ $row['region']->name }}, {{ $row['region']->state }}</p>
                            </a>
                            <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $row['score'] }}</span>
                            <span class="risk-badge risk-badge-{{ $row['band'] }}">{{ $row['band'] }}</span>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-gray-500">No scored regions yet.</li>
                    @endforelse
                </ul>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="section-card overflow-hidden">
                    <div class="section-card-header">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">Recent alerts</h3>
                        <a href="{{ route('alerts.index') }}" class="link-nav text-xs">View all &rarr;</a>
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($recentAlerts as $alert)
                            <li class="px-5 py-3">
                                <p class="text-sm font-medium text-slate-900 dark:text-white">
                                    {{ $alert->index?->name ?? $alert->signalType?->name }} in {{ $alert->region->name }}
                                </p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ $alert->status }} &middot; {{ $alert->triggered_at->diffForHumans() }}
                                </p>
                            </li>
                        @empty
                            <li class="px-5 py-8 text-center text-sm text-gray-500">No alerts yet — you're all clear.</li>
                        @endforelse
                    </ul>
                </div>

                <div class="section-card overflow-hidden">
                    <div class="section-card-header">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">Pipeline activity</h3>
                        <x-live-dot />
                    </div>
                    @if ($activityFeed->isEmpty())
                        <p class="px-5 py-8 text-center text-sm text-gray-500">No ingestion or scoring activity yet.</p>
                    @elseif ($activityFeed->count() < 4)
                        {{-- Too few rows for a loop to read as motion rather than as flicker — just list them. --}}
                        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($activityFeed as $entry)
                                <li class="px-5 py-3">
                                    <p class="text-sm text-slate-900 dark:text-white">{{ $entry['label'] }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                        {{ $entry['value'] }} &middot; {{ $entry['at']->diffForHumans() }}
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="ticker h-[220px] overflow-hidden relative">
                            <div class="ticker-track">
                                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                                    @foreach ($activityFeed as $entry)
                                        <li class="px-5 py-3">
                                            <p class="text-sm text-slate-900 dark:text-white">{{ $entry['label'] }}</p>
                                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                                {{ $entry['value'] }} &middot; {{ $entry['at']->diffForHumans() }}
                                            </p>
                                        </li>
                                    @endforeach
                                </ul>
                                <ul class="divide-y divide-gray-100 dark:divide-gray-700" aria-hidden="true">
                                    @foreach ($activityFeed as $entry)
                                        <li class="px-5 py-3">
                                            <p class="text-sm text-slate-900 dark:text-white">{{ $entry['label'] }}</p>
                                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                                {{ $entry['value'] }} &middot; {{ $entry['at']->diffForHumans() }}
                                            </p>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
