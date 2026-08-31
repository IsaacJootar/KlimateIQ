@php
    use App\Support\RiskBand;
    use Illuminate\Support\Carbon;

    $score = $latest?->score !== null ? (float) $latest->score : null;
    $riskBand = RiskBand::forScore($score);
    $bandPlain = ['green' => 'Low risk', 'amber' => 'Moderate risk', 'red' => 'High risk', 'none' => 'No reading yet'][$riskBand];
    $bandBorder = ['amber' => 'border-amber-500', 'red' => 'border-red-500', 'green' => 'border-emerald-500'][$riskBand] ?? 'border-slate-300';

    // Inline SVG trend line — no charting dependency for a handful of weekly points.
    $chartWidth = 640; $chartHeight = 120; $pad = 10;
    $points = $scores->values();
    $maxScore = max(100, $points->max('score') ?? 100);
    $coords = $points->map(function ($s, $i) use ($points, $chartWidth, $chartHeight, $pad, $maxScore) {
        $x = $points->count() > 1 ? $pad + ($i / ($points->count() - 1)) * ($chartWidth - 2 * $pad) : $chartWidth / 2;
        $y = $s->score === null ? null : $chartHeight - $pad - (($s->score / $maxScore) * ($chartHeight - 2 * $pad));
        return ['x' => $x, 'y' => $y, 'score' => $s->score, 'label' => Carbon::parse($s->period_start)->format('M j')];
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
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">

            {{-- Index picker + what this index measures --}}
            <div class="flex flex-wrap gap-2">
                @foreach ($indices as $idx)
                    <a href="{{ route('regions.show', ['region' => $region->region_id, 'index' => $idx->code]) }}"
                       @if ($idx->description) title="{{ $idx->description }}" @endif
                       class="pill-tab {{ $idx->index_id === $index->index_id ? 'pill-tab-active' : '' }}">
                        {{ $idx->name }}
                    </a>
                @endforeach
            </div>
            @if ($index->description)
                <p class="-mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $index->description }}</p>
            @endif

            @if ($score === null)
                <section class="section-card p-5">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200 mb-2">No score yet for {{ $region->name }}</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        {{ str_replace(' Index', '', $index->name) }} needs its signals ingested and scored first.
                    </p>
                    @if (! empty($breakdown))
                        <p class="mt-4 mb-2 text-xs font-semibold uppercase text-slate-500">Waiting on</p>
                        <ul class="space-y-1.5 text-sm">
                            @foreach ($breakdown as $signal)
                                @php $signalLabel = $signalNames[$signal['signal_type_code']] ?? $signal['signal_type_name'] ?? $signal['signal_type_code']; @endphp
                                <li class="flex gap-2">
                                    <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-slate-400"></span>
                                    <span>
                                        {{ $signalLabel }} —
                                        @if (($signal['status'] ?? null) === 'no_data')
                                            @if (\App\Support\IngestionCadence::isWeekly($signal['signal_type_code']))
                                                <span class="text-gray-400 italic">pending this week's update</span>
                                            @else
                                                <span class="text-gray-400 italic">no data</span>
                                            @endif
                                        @else
                                            {{ $signal['raw_value'] ?? '—' }} {{ $signal['unit'] ?? '' }}
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @else

            @php $n = 0; @endphp

            {{-- 1 — This week --}}
            <section class="section-card p-5">
                <div class="flex items-baseline gap-2.5 mb-3">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-xs font-bold">{{ ++$n }}</span>
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">This week in {{ $region->name }}</h3>
                </div>
                @if ($thisWeek->isNotEmpty())
                    <ul class="space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                        @foreach ($thisWeek as $reading)
                            <li class="flex gap-2">
                                <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-slate-400"></span>
                                <span>{{ $reading['sentence'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-slate-500">Signals for this period are still coming in.</p>
                @endif
                <p class="mt-3 text-xs text-slate-400">
                    Satellite readings for {{ $latest->period_start->format('M j') }}&ndash;{{ $latest->period_end->format('M j, Y') }}.
                </p>
            </section>

            {{-- 2 — The score --}}
            <section class="section-card p-5 border-l-4 {{ $bandBorder }}">
                <div class="flex items-baseline gap-2.5 mb-3">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-xs font-bold">{{ ++$n }}</span>
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">The score</h3>
                </div>
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                    <span class="text-5xl font-bold leading-none text-slate-900 dark:text-white">{{ rtrim(rtrim(number_format($score, 1), '0'), '.') }}</span>
                    <span class="risk-badge risk-badge-{{ $riskBand }} text-sm font-bold uppercase tracking-wide">{{ $bandPlain }}</span>
                    <span class="text-sm text-slate-500 dark:text-slate-400">
                        @if ($trend['direction'] === 'up') &uarr; @elseif ($trend['direction'] === 'down') &darr; @endif
                        {{ $trend['label'] }}
                    </span>
                </div>
                <p class="mt-3 text-xs text-slate-400">
                    0&ndash;100 scale. Green below 34, amber 34&ndash;66, red 67 and above.
                    @if ($region->population !== null) &middot; Population {{ number_format($region->population) }}. @endif
                    &middot; Calculated {{ $latest->calculated_at?->diffForHumans() }}.
                </p>
            </section>

            {{-- 3 — What's driving it --}}
            <section class="section-card p-5">
                <div class="flex items-baseline gap-2.5 mb-3">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-xs font-bold">{{ ++$n }}</span>
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">
                        {{ $riskBand === 'green' ? "What's keeping it low" : "What's pushing it up" }}
                    </h3>
                </div>

                @if (! empty($diagnosis['drivers']))
                    <ul class="space-y-3">
                        @foreach ($diagnosis['drivers'] as $driver)
                            <li>
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                    <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ $driver['label'] }}</span>
                                    <span class="text-xs text-slate-500 dark:text-slate-400">
                                        {{ rtrim(rtrim(number_format($driver['points'], 1), '0'), '.') }} of {{ rtrim(rtrim(number_format($score, 1), '0'), '.') }} points &middot; {{ $driver['share'] }}%
                                    </span>
                                </div>
                                <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                                    <div class="h-full rounded-full bg-primary/70" style="width: {{ min(100, $driver['share']) }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-slate-500">No single signal stands out — the score is spread evenly.</p>
                @endif

                <details class="mt-4 group">
                    <summary class="cursor-pointer text-sm text-primary hover:underline list-none">
                        See the full signal breakdown
                    </summary>
                    <div class="mt-3 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                    <th class="px-3 py-2 whitespace-nowrap">Signal</th>
                                    <th class="px-3 py-2 whitespace-nowrap">Reading</th>
                                    <th class="px-3 py-2 whitespace-nowrap">Signal score</th>
                                    <th class="px-3 py-2 whitespace-nowrap">Weight</th>
                                    <th class="px-3 py-2 whitespace-nowrap">Points</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($breakdown as $signal)
                                    @php $signalLabel = $signalNames[$signal['signal_type_code']] ?? $signal['signal_type_name'] ?? $signal['signal_type_code']; @endphp
                                    <tr>
                                        <td class="px-3 py-2 font-medium whitespace-nowrap">{{ $signalLabel }}</td>
                                        <td class="px-3 py-2">
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
                                        <td class="px-3 py-2 whitespace-nowrap">{{ $signal['normalized_score'] ?? '—' }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ $signal['weight'] }}</td>
                                        <td class="px-3 py-2 font-semibold whitespace-nowrap">{{ $signal['contribution_to_final_score'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t-2 border-gray-300 dark:border-gray-600">
                                    <td colspan="4" class="px-3 py-2 text-right font-semibold">Total</td>
                                    <td class="px-3 py-2 font-bold whitespace-nowrap">{{ rtrim(rtrim(number_format($score, 1), '0'), '.') }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="mt-2 text-xs text-slate-400">
                        Each signal is scored 0&ndash;100 against its calibrated range, multiplied by its weight, and the
                        weighted results are combined. Add every row in the Points column and you get the score.
                    </p>
                </details>
            </section>

            {{-- 4 — What it means --}}
            <section class="section-card p-5">
                <div class="flex items-baseline gap-2.5 mb-3">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-xs font-bold">{{ ++$n }}</span>
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">What it means</h3>
                </div>
                @if ($diagnosis['conclusion'])
                    <p class="text-sm text-gray-800 dark:text-gray-200">
                        <span class="font-semibold">{{ $diagnosis['headline'] }}</span>
                        {{ $diagnosis['conclusion'] }}
                    </p>
                @else
                    <p class="text-sm text-slate-500">Not enough data yet to say what's driving this score.</p>
                @endif

                <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                    @if ($latest->ai_summary)
                        <p class="text-xs font-semibold uppercase text-slate-500 mb-1">AI summary</p>
                        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $latest->ai_summary }}</p>
                        <div class="mt-2 flex items-center gap-3">
                            <span class="text-xs text-slate-400">{{ $latest->ai_summary_model }} &middot; {{ $latest->ai_summary_generated_at?->diffForHumans() }}</span>
                            <form method="POST" action="{{ route('regions.summary', ['region' => $region->region_id, 'index' => $index->code]) }}"
                                  x-data="{ loading: false }" @submit="loading = true">
                                @csrf
                                <button type="submit" class="text-xs text-primary hover:underline" x-bind:disabled="loading || {{ $aiAvailable ? 'false' : 'true' }}">
                                    <span x-show="! loading">Regenerate</span>
                                    <span x-show="loading" x-cloak>regenerating…</span>
                                </button>
                            </form>
                        </div>
                    @elseif ($aiAvailable)
                        <form method="POST" action="{{ route('regions.summary', ['region' => $region->region_id, 'index' => $index->code]) }}"
                              x-data="{ loading: false }" @submit="loading = true">
                            @csrf
                            <button type="submit" class="btn-secondary text-sm" x-bind:disabled="loading">
                                <span x-show="! loading">Want more detail? Generate an AI summary</span>
                                <span x-show="loading" x-cloak class="inline-flex items-center gap-1.5">
                                    <svg class="animate-spin h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    Generating…
                                </span>
                            </button>
                        </form>
                        <p class="mt-1.5 text-xs text-slate-400">A longer plain-English write-up. It only restates the readings above.</p>
                    @endif
                </div>
            </section>

            {{-- 5 — Where it's heading --}}
            <section class="section-card p-5">
                <div class="flex items-baseline gap-2.5 mb-3">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-xs font-bold">{{ ++$n }}</span>
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Where it's heading</h3>
                </div>
                <p class="text-sm text-gray-800 dark:text-gray-200">
                    {{ $trend['label'] }}.
                    @if ($projection) {{ $projection }} @endif
                </p>
                @if ($polyline !== '')
                    <div class="mt-3 overflow-x-auto">
                        <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" class="w-full" style="max-width: {{ $chartWidth }}px;">
                            <polyline points="{{ $polyline }}" fill="none" stroke="#0D9488" stroke-width="2.5" />
                            @foreach ($coords as $c)
                                @if ($c['y'] !== null)
                                    <circle cx="{{ $c['x'] }}" cy="{{ $c['y'] }}" r="3" fill="#0D9488" />
                                @endif
                            @endforeach
                        </svg>
                        <div class="flex justify-between text-xs text-gray-500 mt-1">
                            <span>{{ $coords->first()['label'] ?? '' }}</span>
                            <span>{{ $coords->last()['label'] ?? '' }}</span>
                        </div>
                    </div>
                @else
                    <p class="mt-2 text-xs text-slate-400">Not enough history yet to chart a trend.</p>
                @endif
            </section>

            {{-- 6 — What to do --}}
            @if ($recommendedAction)
                <section class="section-card p-5 border-l-4 {{ $bandBorder }}">
                    <div class="flex items-baseline gap-2.5 mb-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-xs font-bold">{{ ++$n }}</span>
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">What to do</h3>
                    </div>
                    <p class="text-sm text-gray-800 dark:text-gray-200">{{ $recommendedAction }}</p>
                    @if ($diagnosis['dominantSignal'])
                        <p class="mt-2 text-xs text-slate-400">Driven mainly by {{ $diagnosis['dominantSignal'] }} (step 3 above).</p>
                    @endif
                </section>
            @endif

            @endif {{-- score !== null --}}

        </div>
    </div>
</x-app-layout>
