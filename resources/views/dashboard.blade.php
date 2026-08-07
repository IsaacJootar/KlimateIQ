<x-app-layout title="Dashboard">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Dashboard') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            @if ($hasCoverage)
                Your coverage, at a glance — {{ $defaultIndex->name }}.
                <a href="{{ route('coverage.edit') }}" class="link-nav">Change coverage</a>
            @else
                Showing all regions — you haven't scoped your coverage yet.
                <a href="{{ route('coverage.edit') }}" class="link-nav">Set your coverage &rarr;</a>
            @endif
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="{{ route('regions.index') }}" class="metric-card metric-teal">
                    <div class="metric-card-label">{{ $hasCoverage ? 'Your Regions' : 'All Regions' }}</div>
                    <div class="metric-card-value">{{ $regionsCount }}</div>
                    <div class="metric-card-sub">{{ $defaultIndex->name }}</div>
                </a>
                <a href="{{ route('regions.index') }}" class="metric-card metric-red">
                    <div class="metric-card-label">High Risk</div>
                    <div class="metric-card-value">{{ $highRiskCount }}</div>
                    <div class="metric-card-sub">score &ge; 67</div>
                </a>
                <a href="{{ route('alerts.index') }}" class="metric-card metric-amber">
                    <div class="metric-card-label">Open Alerts</div>
                    <div class="metric-card-value">{{ $openAlertsCount }}</div>
                    <div class="metric-card-sub">need your attention</div>
                </a>
                <a href="{{ route('thresholds.index') }}" class="metric-card metric-slate">
                    <div class="metric-card-label">Active Thresholds</div>
                    <div class="metric-card-value">{{ $activeThresholdsCount }}</div>
                    <div class="metric-card-sub">configured</div>
                </a>
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
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($activityFeed as $entry)
                            <li class="px-5 py-3">
                                <p class="text-sm text-slate-900 dark:text-white">{{ $entry['label'] }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ $entry['value'] }} &middot; {{ $entry['at']->diffForHumans() }}
                                </p>
                            </li>
                        @empty
                            <li class="px-5 py-8 text-center text-sm text-gray-500">No ingestion or scoring activity yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
