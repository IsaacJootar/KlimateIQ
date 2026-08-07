@php
    $highRisk = $regions->where('risk_band', 'red');
    $scored = $regions->whereNotNull('current_score');
    $avgScore = $scored->count() ? round($scored->avg('current_score'), 1) : null;
    $populationAtRisk = $highRisk->sum('population');
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Regions') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="flex flex-wrap gap-2">
                @foreach ($indices as $idx)
                    <a href="{{ route('regions.index', ['index' => $idx->code]) }}"
                       class="pill-tab {{ $idx->index_id === $index->index_id ? 'pill-tab-active' : '' }}">
                        {{ $idx->name }}
                    </a>
                @endforeach
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="metric-card metric-teal">
                    <div class="metric-card-label">Regions Monitored</div>
                    <div class="metric-card-value">{{ $regions->count() }}</div>
                    <div class="metric-card-sub">{{ $index->name }}</div>
                </div>
                <div class="metric-card metric-red">
                    <div class="metric-card-label">High Risk</div>
                    <div class="metric-card-value">{{ $highRisk->count() }}</div>
                    <div class="metric-card-sub">score &ge; 67</div>
                </div>
                <div class="metric-card metric-amber">
                    <div class="metric-card-label">Population at Risk</div>
                    <div class="metric-card-value">{{ number_format($populationAtRisk) }}</div>
                    <div class="metric-card-sub">in high-risk regions</div>
                </div>
                <div class="metric-card metric-slate">
                    <div class="metric-card-label">Average Score</div>
                    <div class="metric-card-value">{{ $avgScore ?? '—' }}</div>
                    <div class="metric-card-sub">across monitored regions</div>
                </div>
            </div>

            <div class="section-card">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Map</h3>
                </div>
                <div id="region-map" style="height: 420px;"></div>
            </div>

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">All Regions</h3>
                </div>
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3">Region</th>
                            <th class="px-4 py-3">State</th>
                            <th class="px-4 py-3">Population</th>
                            <th class="px-4 py-3">{{ $index->name }}</th>
                            <th class="px-4 py-3">Risk</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($regions as $region)
                            <tr class="nav-card">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $region->name }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500">{{ $region->state }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500">{{ number_format($region->population) }}</td>
                                <td class="px-4 py-3 text-sm font-semibold">{{ $region->current_score ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="risk-badge risk-badge-{{ $region->risk_band }}">{{ $region->risk_band === 'none' ? 'no data' : $region->risk_band }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-right">
                                    <a href="{{ route('regions.show', ['region' => $region->region_id, 'index' => $index->code]) }}" class="link-nav">View &rarr;</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @php
        $mapRegions = $regions->map(fn ($r) => [
            'name' => $r->name,
            'latitude' => $r->latitude,
            'longitude' => $r->longitude,
            'score' => $r->current_score,
            'riskBand' => $r->risk_band,
            'url' => route('regions.show', ['region' => $r->region_id, 'index' => $index->code]),
        ])->values();
    @endphp

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                window.initRegionMap('region-map', @json($mapRegions));
            });
        </script>
    @endpush
</x-app-layout>
