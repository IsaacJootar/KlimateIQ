<x-app-layout title="Platform Overview">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Platform Overview') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Every agency, every active region — not just your own coverage. Visible because your
            organization has been granted cross-agency oversight.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="metric-card metric-teal">
                    <div class="metric-card-label">Active Regions</div>
                    <div class="metric-card-value">{{ $activeRegionsCount }}</div>
                    <div class="metric-card-sub">platform-wide</div>
                </div>
                <div class="metric-card metric-red">
                    <div class="metric-card-label">Open Alerts</div>
                    <div class="metric-card-value">{{ $openAlertsCount }}</div>
                    <div class="metric-card-sub">across every agency</div>
                </div>
                <div class="metric-card metric-slate">
                    <div class="metric-card-label">Agencies</div>
                    <div class="metric-card-value">{{ $agencyCount }}</div>
                    <div class="metric-card-sub">registered on the platform</div>
                </div>
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

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">By agency</h3>
                    <span class="text-xs text-slate-500 dark:text-slate-400">Sorted by open alerts</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3">Agency</th>
                                <th class="px-4 py-3">Members</th>
                                <th class="px-4 py-3">Regions Watched</th>
                                <th class="px-4 py-3">Open Alerts</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($agencyBreakdown as $row)
                                <tr>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ $row['agency']->name }}
                                        @if ($row['agency']->federal_oversight)
                                            <span class="risk-badge risk-badge-green ml-1">oversight</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $row['user_count'] }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $row['regions_watched'] }}</td>
                                    <td class="px-4 py-3 text-sm font-semibold">{{ $row['open_alerts'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">No agencies yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
