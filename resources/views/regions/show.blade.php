@php
    $riskBand = match (true) {
        $latest === null || $latest->score === null => 'none',
        $latest->score < 34 => 'green',
        $latest->score < 67 => 'amber',
        default => 'red',
    };
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
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

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
                </div>
                <div class="metric-card metric-teal">
                    <div class="metric-card-label">Population</div>
                    <div class="metric-card-value">{{ number_format($region->population) }}</div>
                    <div class="metric-card-sub">{{ $region->lga_code }}</div>
                </div>
                <div class="metric-card metric-slate">
                    <div class="metric-card-label">Last Calculated</div>
                    <div class="metric-card-value" style="font-size:1.1rem;">{{ $latest?->calculated_at?->diffForHumans() ?? '—' }}</div>
                    <div class="metric-card-sub">{{ $latest?->scoring_strategy ?? '' }}</div>
                </div>
            </div>

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
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3">Signal</th>
                                <th class="px-4 py-3">Raw Value</th>
                                <th class="px-4 py-3">Normalized</th>
                                <th class="px-4 py-3">Weight</th>
                                <th class="px-4 py-3">Contribution</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($breakdown as $signal)
                                <tr>
                                    <td class="px-4 py-3 text-sm font-medium">{{ $signal['signal_type_code'] }}</td>
                                    <td class="px-4 py-3 text-sm">
                                        @if (($signal['status'] ?? null) === 'no_data')
                                            <span class="text-gray-400 italic">no data</span>
                                        @else
                                            {{ $signal['raw_value'] }} {{ $signal['unit'] ?? '' }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-sm">{{ $signal['normalized_score'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm">{{ $signal['weight'] }}</td>
                                    <td class="px-4 py-3 text-sm font-semibold">{{ $signal['contribution'] ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No score calculated yet for this index.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if (session('success'))
                <div class="p-4 rounded-lg bg-green-50 dark:bg-green-900 text-green-700 dark:text-green-200 text-sm">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error'))
                <div class="p-4 rounded-lg bg-amber-50 dark:bg-amber-900 text-amber-700 dark:text-amber-200 text-sm">
                    {{ session('error') }}
                </div>
            @endif

            <div class="section-card p-4">
                <div class="flex items-center justify-between mb-1">
                    <div class="text-xs font-semibold uppercase text-gray-500">AI Summary</div>
                    @if ($latest?->score !== null)
                        <form method="POST" action="{{ route('regions.summary', ['region' => $region->region_id, 'index' => $index->code]) }}">
                            @csrf
                            <button type="submit" class="btn-secondary" {{ $aiAvailable ? '' : 'disabled' }}>
                                {{ $latest?->ai_summary ? 'Regenerate summary' : 'Generate summary' }}
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
