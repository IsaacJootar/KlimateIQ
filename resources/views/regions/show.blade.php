@php
    use App\Support\RiskBand;
    use Illuminate\Support\Carbon;

    $score = $latest?->score !== null ? (float) $latest->score : null;
    $riskBand = RiskBand::forScore($score);
    $bandPlain = ['green' => 'Low risk', 'amber' => 'Moderate risk', 'red' => 'High risk', 'none' => 'No reading yet'][$riskBand];
    $heroClass = 'score-hero-'.$riskBand;
    $fillClass = 'driver-fill-'.$riskBand;
    $accentClass = 'step-accent-'.$riskBand;
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');

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
            <x-index-tabs :groups="$indexGroups" :active="$index" route-name="regions.show" :route-params="['region' => $region->region_id]" />
            @if ($index->description)
                <div class="-mt-1 flex gap-2.5 rounded-xl bg-gano-50 dark:bg-gano-950/50 border border-gano-100 dark:border-gano-900 px-3.5 py-2.5">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-gano-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>
                    </svg>
                    <p class="text-sm text-gano-900 dark:text-gano-100">{{ $index->description }}</p>
                </div>
            @endif

            @if ($isForecast)
                @include('regions.partials.forecast')
            @elseif ($score === null)
                <section class="section-card p-5 step-accent-none">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200 mb-2">No score yet for {{ $region->name }}</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        {{ str_replace(' Index', '', $index->name) }} needs its signals ingested and scored first.
                    </p>
                    @if (! empty($breakdown))
                        <p class="mt-4 mb-2 text-xs font-semibold uppercase tracking-wide text-gano-700 dark:text-gano-300">Waiting on</p>
                        <ul class="space-y-1.5 text-sm">
                            @foreach ($breakdown as $signal)
                                @php $signalLabel = $signalNames[$signal['signal_type_code']] ?? $signal['signal_type_name'] ?? $signal['signal_type_code']; @endphp
                                <li class="flex gap-2">
                                    <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-gano-400"></span>
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

            {{-- 1 — This week --}}
            <section class="section-card p-5">
                <div class="flex items-baseline gap-3 mb-3">
                    <span class="step-badge">1</span>
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">This week in {{ $region->name }}</h3>
                </div>
                @if ($thisWeek->isNotEmpty())
                    <ul class="space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                        @foreach ($thisWeek as $reading)
                            <li class="flex gap-2.5">
                                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-gano-400"></span>
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

            {{-- 2 — The score (hero) --}}
            <section class="score-hero {{ $heroClass }}">
                <div class="flex items-center gap-3 mb-3">
                    <span class="step-badge">2</span>
                    <h3 class="font-semibold text-white/95">The score</h3>
                </div>
                <div class="flex flex-wrap items-end gap-x-5 gap-y-2">
                    <span class="score-hero-number">{{ $num($score) }}</span>
                    <span class="score-hero-pill mb-1.5">{{ $bandPlain }}</span>
                    <span class="mb-1.5 text-sm text-white/85">
                        @if ($trend['direction'] === 'up') &uarr; @elseif ($trend['direction'] === 'down') &darr; @endif
                        {{ $trend['label'] }}
                    </span>
                </div>
                <p class="mt-3 text-xs text-white/70">
                    0&ndash;100 scale. Green below 34, amber 34&ndash;66, red 67 and above.
                    @if ($region->population !== null) &middot; Population {{ number_format($region->population) }}. @endif
                    &middot; Calculated {{ $latest->calculated_at?->diffForHumans() }}.
                </p>
                @if ($calibrationNote)
                    <p class="mt-1.5 text-xs text-white/60 italic">{{ $calibrationNote }}</p>
                @endif
            </section>

            {{-- 3 — What's driving it --}}
            <section class="section-card p-5">
                <div class="flex items-baseline gap-3 mb-4">
                    <span class="step-badge">3</span>
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">
                        {{ $riskBand === 'green' ? "What's keeping it low" : "What's pushing it up" }}
                    </h3>
                </div>

                @if (! empty($drivers))
                    <ul class="space-y-4">
                        @foreach ($drivers as $driver)
                            <li>
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                    <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ $driver['label'] }}</span>
                                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400 tabular-nums">
                                        {{ $num($driver['points']) }} of {{ $num($score) }} pts &middot; {{ $driver['share'] }}%
                                    </span>
                                </div>
                                @if ($driver['reading'])
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $driver['reading'] }}</p>
                                @endif
                                <div class="driver-track mt-1.5">
                                    <div class="driver-fill {{ $fillClass }}" style="width: {{ max(3, min(100, $driver['share'])) }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-slate-500">No single signal stands out — the score is spread evenly.</p>
                @endif

                <details class="mt-5 group">
                    <summary class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-slate-100 dark:bg-slate-700/60 px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 list-none">
                        <svg class="h-3.5 w-3.5 transition-transform group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                        See the full signal breakdown
                    </summary>
                    <div class="mt-3 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400 bg-slate-50 dark:bg-slate-800/60">
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
                                        <td class="px-3 py-2 whitespace-nowrap tabular-nums">{{ $signal['normalized_score'] ?? '—' }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap tabular-nums">{{ $signal['weight'] }}</td>
                                        <td class="px-3 py-2 font-semibold whitespace-nowrap tabular-nums">{{ $signal['contribution_to_final_score'] ?? $signal['contribution'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t-2 border-gray-300 dark:border-gray-600">
                                    <td colspan="4" class="px-3 py-2 text-right font-semibold">Total</td>
                                    <td class="px-3 py-2 font-bold whitespace-nowrap tabular-nums">{{ $num($score) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </details>
            </section>

            {{-- 4 — What it means --}}
            <section class="section-card p-5">
                <div class="flex items-baseline gap-3 mb-3">
                    <span class="step-badge">4</span>
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">What it means</h3>
                </div>
                @if ($diagnosis['conclusion'])
                    <p class="text-sm leading-relaxed text-gray-800 dark:text-gray-200">
                        <span class="font-semibold">{{ $diagnosis['headline'] }}</span>
                        {{ $diagnosis['conclusion'] }}
                    </p>
                @else
                    <p class="text-sm text-slate-500">Not enough data yet to say what's driving this score.</p>
                @endif

                <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                    @if ($latest->ai_summary)
                        <p class="text-xs font-semibold uppercase tracking-wide text-gano-700 dark:text-gano-300 mb-1">AI summary</p>
                        <p class="text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ $latest->ai_summary }}</p>
                        <div class="mt-2 flex items-center gap-3">
                            <span class="text-xs text-slate-400">{{ $latest->ai_summary_model }} &middot; {{ $latest->ai_summary_generated_at?->diffForHumans() }}</span>
                            <form method="POST" action="{{ route('regions.summary', ['region' => $region->region_id, 'index' => $index->code]) }}"
                                  x-data="{ loading: false }" @submit="loading = true">
                                @csrf
                                <button type="submit" class="text-xs font-semibold text-gano-700 dark:text-gano-400 hover:underline" x-bind:disabled="loading || {{ $aiAvailable ? 'false' : 'true' }}">
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
                    @endif
                </div>
            </section>

            {{-- 5 — Where it's heading --}}
            <section class="section-card p-5">
                <div class="flex items-baseline gap-3 mb-3">
                    <span class="step-badge">5</span>
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Where it's heading</h3>
                </div>

                @if ($forecastTrajectory)
                    @php
                        $fc = $forecastTrajectory['daily'];
                        $fw = 640; $fh = 130; $fpad = 12;
                        $fcoords = $fc->values()->map(function ($d, $i) use ($fc, $fw, $fh, $fpad) {
                            $x = $fc->count() > 1 ? $fpad + ($i / ($fc->count() - 1)) * ($fw - 2 * $fpad) : $fw / 2;
                            $y = $fh - $fpad - (($d['score'] / 100) * ($fh - 2 * $fpad));
                            return ['x' => round($x, 1), 'y' => round($y, 1), 'd' => $d];
                        });
                        $fline = $fcoords->map(fn ($c) => "{$c['x']},{$c['y']}")->implode(' ');
                        $amberY = $fh - $fpad - (34 / 100) * ($fh - 2 * $fpad);
                        $redY = $fh - $fpad - (67 / 100) * ($fh - 2 * $fpad);

                        // p10–p90 fan (T5), aligned to the score line by date.
                        $ffanByDate = collect($forecastTrajectory['fan'] ?? [])->keyBy('date');
                        $fyFor = fn ($v) => $fh - $fpad - (max(0, min(100, (float) $v)) / 100) * ($fh - 2 * $fpad);
                        $ffanTop = $fcoords->filter(fn ($c) => $ffanByDate->has($c['d']['date']))
                            ->map(fn ($c) => "{$c['x']},".round($fyFor($ffanByDate[$c['d']['date']]['p90']), 1));
                        $ffanBottom = $fcoords->filter(fn ($c) => $ffanByDate->has($c['d']['date']))
                            ->map(fn ($c) => "{$c['x']},".round($fyFor($ffanByDate[$c['d']['date']]['p10']), 1))->reverse();
                        $ffanPolygon = $ffanTop->concat($ffanBottom)->implode(' ');
                    @endphp
                    <p class="text-sm leading-relaxed text-gray-800 dark:text-gray-200">
                        {{ $trend['label'] }}. <span class="font-semibold">{{ $forecastTrajectory['line'] }}</span>
                    </p>
                    @if (! empty($forecastTrajectory['probability_line']))
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $forecastTrajectory['probability_line'] }}</p>
                        <p class="text-xs text-slate-400">Worked out by running the forecast about 50 times with slightly different starting conditions.</p>
                    @endif
                    @if ($fc->count() > 1)
                        <div class="mt-3 overflow-x-auto">
                            <svg viewBox="0 0 {{ $fw }} {{ $fh }}" class="w-full" style="max-width: {{ $fw }}px;">
                                @if ($ffanPolygon !== '')
                                    <polygon points="{{ $ffanPolygon }}" fill="#0D9488" fill-opacity="0.14" />
                                @endif
                                <line x1="{{ $fpad }}" y1="{{ $redY }}" x2="{{ $fw - $fpad }}" y2="{{ $redY }}" stroke="#DC2626" stroke-width="1" stroke-dasharray="3 3" stroke-opacity="0.5" />
                                <line x1="{{ $fpad }}" y1="{{ $amberY }}" x2="{{ $fw - $fpad }}" y2="{{ $amberY }}" stroke="#D97706" stroke-width="1" stroke-dasharray="3 3" stroke-opacity="0.5" />
                                <polyline points="{{ $fline }}" fill="none" stroke="#0D9488" stroke-width="2.5" stroke-linejoin="round" />
                                @foreach ($fcoords as $c)
                                    <circle cx="{{ $c['x'] }}" cy="{{ $c['y'] }}"
                                            r="{{ $c['d']['date'] === $forecastTrajectory['peak_date'] ? 4 : 2.5 }}"
                                            fill="{{ $c['d']['date'] === $forecastTrajectory['peak_date'] ? '#0F766E' : '#0D9488' }}" />
                                @endforeach
                            </svg>
                            <div class="flex justify-between text-xs text-gray-500 mt-1">
                                <span>{{ \Illuminate\Support\Carbon::parse($fc->first()['date'])->format('M j') }}</span>
                                <span>{{ \Illuminate\Support\Carbon::parse($fc->last()['date'])->format('M j') }}</span>
                            </div>
                        </div>
                    @endif
                    <p class="mt-2 text-xs text-slate-400">
                        Forecast from the Open-Meteo model — dashed lines mark the amber (34) and red (67) bands.
                        @if ($ffanPolygon !== '') The shaded band is the ensemble's 10th–90th percentile range. @endif
                        This is a forecast, not a current reading.
                    </p>
                @else
                <p class="text-sm leading-relaxed text-gray-800 dark:text-gray-200">
                    {{ $trend['label'] }}.
                    @if ($projection) {{ $projection }} @endif
                </p>
                @if ($polyline !== '')
                    <div class="mt-3 overflow-x-auto">
                        <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" class="w-full" style="max-width: {{ $chartWidth }}px;">
                            <defs>
                                <linearGradient id="trendFill" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#0D9488" stop-opacity="0.18" />
                                    <stop offset="100%" stop-color="#0D9488" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                            @if ($coords->count() > 1)
                                <polygon points="{{ $polyline }} {{ round($coords->last()['x'], 1) }},{{ $chartHeight }} {{ round($coords->first()['x'], 1) }},{{ $chartHeight }}" fill="url(#trendFill)" />
                            @endif
                            <polyline points="{{ $polyline }}" fill="none" stroke="#0D9488" stroke-width="2.5" stroke-linejoin="round" />
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
                @endif
            </section>

            {{-- 6 — What to do --}}
            @if ($recommendedAction)
                <section class="section-card p-5 {{ $accentClass }}">
                    <div class="flex items-baseline gap-3 mb-3">
                        <span class="step-badge">6</span>
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">What to do</h3>
                    </div>
                    @if ($cropLine)
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">
                            Rain-fed crops most exposed here now typically include {{ $cropLine }}.
                        </p>
                        <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">
                            Examples for this zone and month, not a full field survey. The bracket is the crop's growth
                            phase — a dry spell now costs the most yield.
                        </p>
                    @endif
                    @if ($facilities)
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">
                            {{ $facilities['label'] }} — for example: {{ implode(', ', $facilities['names']) }}.
                        </p>
                        <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">
                            On record ({{ $facilities['attribution'] }}) — confirm which are operating locally.
                            <a href="{{ route('regions.facilities', $region->region_id) }}" class="font-medium text-primary hover:underline">See all in this LGA</a>.
                        </p>
                    @endif
                    <p class="text-sm leading-relaxed text-gray-800 dark:text-gray-200">{{ $recommendedAction }}</p>
                    @if ($diagnosis['dominantSignal'])
                        <p class="mt-2 text-xs text-slate-400">Driven mainly by {{ $diagnosis['dominantSignal'] }} (step 3 above).</p>
                    @endif
                </section>
            @endif

            @endif {{-- score !== null --}}

        </div>
    </div>
</x-app-layout>
