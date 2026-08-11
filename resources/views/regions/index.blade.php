@php
    $highRisk = $regions->where('risk_band', 'red');
    $scored = $regions->whereNotNull('current_score');
    $avgScore = $scored->count() ? round($scored->avg('current_score'), 1) : null;
    $populationAtRisk = $highRisk->sum('population');
@endphp

<x-app-layout title="Regions">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Regions') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            @if ($hasCoverage)
                Your configured regions, color-coded by current risk. Switch the index above to see a different score.
            @else
                Every region currently active on the platform — you haven't set your own coverage yet.
                <a href="{{ route('coverage.edit') }}" class="link-nav">Set your coverage &rarr;</a>
            @endif
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

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
                    <div class="metric-card-label">{{ $hasCoverage ? 'Your Regions' : 'Active Regions' }}</div>
                    <div class="metric-card-value"><x-count-up :value="$regions->count()" /></div>
                    <div class="metric-card-sub">{{ $index->name }}</div>
                </div>
                <div class="metric-card metric-red">
                    <div class="metric-card-label">High Risk</div>
                    <div class="metric-card-value"><x-count-up :value="$highRisk->count()" /></div>
                    <div class="metric-card-sub">score &ge; 67</div>
                </div>
                <div class="metric-card metric-amber">
                    <div class="metric-card-label">Population at Risk</div>
                    <div class="metric-card-value"><x-count-up :value="$populationAtRisk" comma /></div>
                    <div class="metric-card-sub">in high-risk regions</div>
                </div>
                <div class="metric-card metric-slate">
                    <div class="metric-card-label">Average Score</div>
                    <div class="metric-card-value">
                        @if ($avgScore !== null)
                            <x-count-up :value="$avgScore" :decimals="1" />
                        @else
                            &mdash;
                        @endif
                    </div>
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
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3 whitespace-nowrap">Region</th>
                                <th class="px-4 py-3 whitespace-nowrap">State</th>
                                <th class="px-4 py-3 whitespace-nowrap">Population</th>
                                <th class="px-4 py-3 whitespace-nowrap">{{ $index->name }}</th>
                                <th class="px-4 py-3 whitespace-nowrap">Risk</th>
                                <th class="px-4 py-3 whitespace-nowrap">History</th>
                                <th class="px-4 py-3 whitespace-nowrap">2-Week Trend</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($regions as $region)
                                <tr class="nav-card">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap">{{ $region->name }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $region->state }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $region->population !== null ? number_format($region->population) : '—' }}</td>
                                    <td class="px-4 py-3 text-sm font-semibold whitespace-nowrap">{{ $region->current_score ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm whitespace-nowrap">
                                        <span class="risk-badge risk-badge-{{ $region->risk_band }}">{{ $region->risk_band === 'none' ? 'no data' : $region->risk_band }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-sm whitespace-nowrap">
                                        <x-sparkline :values="$region->sparkline" :band="$region->risk_band" />
                                    </td>
                                    <td class="px-4 py-3 text-sm whitespace-nowrap {{ ['up' => 'text-red-600 dark:text-red-400', 'down' => 'text-emerald-600 dark:text-emerald-400', 'flat' => 'text-slate-500', 'unknown' => 'text-slate-400'][$region->trend['direction']] }}">
                                        @if ($region->trend['direction'] === 'up') &uarr; @elseif ($region->trend['direction'] === 'down') &darr; @endif
                                        {{ $region->trend['label'] }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-right whitespace-nowrap">
                                        <a href="{{ route('regions.show', ['region' => $region->region_id, 'index' => $index->code]) }}" class="link-nav">View &rarr;</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
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
