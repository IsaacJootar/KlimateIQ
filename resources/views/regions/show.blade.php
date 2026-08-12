@php
    $riskBand = \App\Support\RiskBand::forScore($latest?->score);
    $metricClass = ['green' => 'metric-green', 'amber' => 'metric-amber', 'red' => 'metric-red', 'none' => 'metric-slate'][$riskBand];

    // Inline SVG trend line — no charting dependency for a handful of weekly points.
    $chartWidth = 640; $chartHeight = 140; $pad = 12;
    $points = $scores->values();
    $maxScore = max(100, $points->max('score') ?? 100);
    $coords = $points->map(function ($s, $i) use ($points, $chartWidth, $chartHeight, $pad, $maxScore) {
        $x = $points->count() > 1 ? $pad + ($i / ($points->count() - 1)) * ($chartWidth - 2 * $pad) : $chartWidth / 2;
        $y = $s->score === null ? null : $chartHeight - $pad - (($s->score / $maxScore) * ($chartHeight - 2 * $pad));
        return ['x' => $x, 'y' => $y, 'score' => $s->score, 'label' => \Illuminate\Support\Carbon::parse($s->period_start)->format('M j')];
    });
    $polyline = $coords->whereNotNull('y')->map(fn ($c) => round($c['x'], 1).','.round($c['y'], 1))->implode(' ');
@endphp

<x-app-layout :title="$region->name">
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ $region->name }}, {{ $region->state }}
            </h2>
            <a href="{{ route('regions.index', ['index' => $index->code]) }}" class="link-nav">&larr; All regions</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="flex flex-wrap gap-2">
                @foreach ($indices as $idx)
                    <a href="{{ route('regions.show', ['region' => $region->region_id, 'index' => $idx->code]) }}"
                       class="pill-tab {{ $idx->index_id === $index->index_id ? 'pill-tab-active' : '' }}">
                        {{ $idx->name }}
                    </a>
                @endforeach
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="metric-card {{ $metricClass }}">
                    <div class="metric-card-label">{{ $index->name }}</div>
                    <div class="metric-card-value">{{ $latest?->score ?? '—' }}</div>
                    <div class="metric-card-sub">{{ $riskBand === 'none' ? 'no data yet' : ucfirst($riskBand).' risk' }}</div>
                    <div class="metric-card-sub">
                        @if ($trend['direction'] === 'up') &uarr; @elseif ($trend['direction'] === 'down') &darr; @endif
                        {{ $trend['label'] }}
                    </div>
                </div>
                <div class="metric-card metric-teal">
                    <div class="metric-card-label">Population</div>
                    <div class="metric-card-value">{{ $region->population !== null ? number_format($region->population) : '—' }}</div>
                    <div class="metric-card-sub">{{ $region->lga_code }}</div>
                </div>
                <div class="metric-card metric-slate">
                    <div class="metric-card-label">Last Calculated</div>
                    <div class="metric-card-value" style="font-size:1.1rem;">{{ $latest?->calculated_at?->diffForHumans() ?? '—' }}</div>
                    <div class="metric-card-sub">{{ $latest?->scoring_strategy ?? '' }}</div>
                </div>
            </div>

            @if ($recommendedAction)
                <div class="section-card p-4 border-l-4 {{ ['amber' => 'border-amber-500', 'red' => 'border-red-500'][$riskBand] ?? 'border-gano-500' }}">
                    <div class="text-xs font-semibold uppercase text-gray-500 mb-1">Recommended action</div>
                    <p class="text-sm text-gray-800 dark:text-gray-200">{{ $recommendedAction }}</p>
                </div>
            @endif

            <div class="section-card">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Trend</h3>
                </div>
                <div class="p-4 overflow-x-auto">
                    @if ($polyline !== '')
                        <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" class="w-full" style="max-width: {{ $chartWidth }}px;">
                            <polyline points="{{ $polyline }}" fill="none" stroke="#0D9488" stroke-width="2.5" />
                            @foreach ($coords as $c)
                                @if ($c['y'] !== null)
                                    <circle cx="{{ $c['x'] }}" cy="{{ $c['y'] }}" r="3.5" fill="#0D9488" />
                                @endif
                            @endforeach
                        </svg>
                        <div class="flex justify-between text-xs text-gray-500 mt-1">
                            <span>{{ $coords->first()['label'] ?? '' }}</span>
                            <span>{{ $coords->last()['label'] ?? '' }}</span>
                        </div>
                    @else
                        <p class="text-sm text-gray-500">Not enough history yet to chart a trend.</p>
                    @endif
                </div>
            </div>

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">What's driving this score</h3>
                </div>
                <p class="px-4 pt-3 text-xs text-gray-500 dark:text-gray-400">
                    @if ($latest?->score !== null)
                        "Signal Score" is this signal's own 0&ndash;100 reading. "Contribution to Final Score" is how
                        many of the {{ $latest->score }} points at the top actually came from this signal &mdash; add
                        every row in that column together and you get the score above.
                    @else
                        "Signal Score" is this signal's own 0&ndash;100 reading. "Contribution to Final Score" would
                        show how many points each signal adds to the final score, once there's enough data to
                        calculate one.
                    @endif
                </p>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3 whitespace-nowrap">Signal</th>
                                <th class="px-4 py-3 whitespace-nowrap">Raw Value</th>
                                <th class="px-4 py-3 whitespace-nowrap">Signal Score</th>
                                <th class="px-4 py-3 whitespace-nowrap">Weight</th>
                                <th class="px-4 py-3 whitespace-nowrap">Contribution to Final Score</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($breakdown as $signal)
                                <tr>
                                    <td class="px-4 py-3 text-sm font-medium whitespace-nowrap">{{ $signal['signal_type_code'] }}</td>
                                    <td class="px-4 py-3 text-sm">
                                        @if (($signal['status'] ?? null) === 'no_data')
                                            @if (\App\Support\IngestionCadence::isWeekly($signal['signal_type_code']))
                                                <span class="text-gray-400 italic">pending this week's update</span>
                                            @else
                                                <span class="text-gray-400 italic">no data</span>
                                            @endif
                                        @else
                                            {{ $signal['raw_value'] }} {{ $signal['unit'] ?? '' }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-sm whitespace-nowrap">{{ $signal['normalized_score'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm whitespace-nowrap">{{ $signal['weight'] }}</td>
                                    <td class="px-4 py-3 text-sm font-semibold whitespace-nowrap">{{ $signal['contribution_to_final_score'] ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No score calculated yet for this index.</td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if (! empty($breakdown))
                            <tfoot>
                                <tr class="border-t-2 border-gray-300 dark:border-gray-600">
                                    <td colspan="4" class="px-4 py-3 text-sm font-semibold text-right">Total (this region's score)</td>
                                    <td class="px-4 py-3 text-sm font-bold whitespace-nowrap">{{ $latest?->score ?? '—' }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>

            <div class="section-card p-4">
                <div class="flex items-center justify-between mb-1">
                    <div class="text-xs font-semibold uppercase text-gray-500">AI Summary</div>
                    @if ($latest?->score !== null)
                        <form method="POST" action="{{ route('regions.summary', ['region' => $region->region_id, 'index' => $index->code]) }}"
                              x-data="{ loading: false }" @submit="loading = true">
                            @csrf
                            <button type="submit" class="btn-secondary" x-bind:disabled="loading || {{ $aiAvailable ? 'false' : 'true' }}">
                                <span x-show="! loading">{{ $latest?->ai_summary ? 'Regenerate summary' : 'Generate summary' }}</span>
                                <span x-show="loading" x-cloak class="inline-flex items-center gap-1.5">
                                    <svg class="animate-spin h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    AI is generating summary...
                                </span>
                            </button>
                        </form>
                    @endif
                </div>
                @if ($latest?->ai_summary)
                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ $latest->ai_summary }}</p>
                    <p class="text-xs text-gray-400 mt-1">{{ $latest->ai_summary_model }} &middot; {{ $latest->ai_summary_generated_at?->diffForHumans() }}</p>
                @elseif (! $aiAvailable)
                    <p class="text-sm text-gray-500">AI summaries need an OpenAI API key configured (<code>OPENAI_API_KEY</code>).</p>
                @else
                    <p class="text-sm text-gray-500">No summary generated yet.</p>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
