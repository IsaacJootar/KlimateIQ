@php
    use App\Support\RiskBand;
    use Illuminate\Support\Carbon;

    $band = RiskBand::forScore($peakScore);
    $bandPlain = ['green' => 'Low risk', 'amber' => 'Moderate risk', 'red' => 'High risk', 'none' => 'No forecast'][$band];
    $issued = $forecast?->forecast_issued_at;
    $peakDate = $forecast?->peak_date;
    $lead = $forecast?->lead_days_to_peak;

    // The daily forecast curve — plot the 0–100 score per lead day.
    $w = 640; $h = 130; $pad = 12;
    $pts = $forecastDaily->values();
    $coords = $pts->map(function ($d, $i) use ($pts, $w, $h, $pad) {
        $x = $pts->count() > 1 ? $pad + ($i / ($pts->count() - 1)) * ($w - 2 * $pad) : $w / 2;
        $y = $h - $pad - (($d['score'] / 100) * ($h - 2 * $pad));
        return ['x' => round($x, 1), 'y' => round($y, 1), 'd' => $d];
    });
    $line = $coords->map(fn ($c) => "{$c['x']},{$c['y']}")->implode(' ');
    $firstDischarge = $forecastDaily->first()['discharge'] ?? null;
    $peakDischarge = $forecastDaily->sortByDesc('score')->first()['discharge'] ?? null;
    $num = fn ($v) => $v === null ? '—' : number_format((float) $v, 0);

    // p10–p90 fan band (T5), aligned to the same x-axis as the score line by lead-day index.
    $fanByDate = collect($forecastFan ?? [])->keyBy('date');
    $yFor = fn ($v) => $h - $pad - (max(0, min(100, (float) $v)) / 100) * ($h - 2 * $pad);
    $fanTop = $coords->filter(fn ($c) => $fanByDate->has($c['d']['date']))
        ->map(fn ($c) => "{$c['x']},".round($yFor($fanByDate[$c['d']['date']]['p90']), 1));
    $fanBottom = $coords->filter(fn ($c) => $fanByDate->has($c['d']['date']))
        ->map(fn ($c) => "{$c['x']},".round($yFor($fanByDate[$c['d']['date']]['p10']), 1))
        ->reverse();
    $fanPolygon = $fanTop->concat($fanBottom)->implode(' ');
@endphp

@if ($peakScore === null && ($forecastStatus ?? 'no_coverage') === 'calibration_pending')
    <section class="section-card p-5 step-accent-none">
        <h3 class="font-semibold text-gray-800 dark:text-gray-200 mb-2">River-flood scoring for {{ $region->name }} isn't calibrated yet</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400">
            GloFAS models a river reach here and a forecast has been pulled, but this reach's own flood
            thresholds (the flow levels that count as a 2-year or 20-year flood) haven't been derived yet.
            No score is shown rather than a misleading one — the discharge forecast itself is below, and a
            calibrated score will appear once the reach is processed.
        </p>
    </section>
    @if (($pendingDischarge ?? collect())->isNotEmpty())
        <section class="section-card p-5">
            <h3 class="font-semibold text-gray-800 dark:text-gray-200 mb-2">The discharge forecast</h3>
            <p class="text-sm text-gray-700 dark:text-gray-300">
                River flow is forecast to
                @if ($pendingDischarge->last() > $pendingDischarge->first() * 1.05)
                    rise from about {{ $num($pendingDischarge->first()) }} to {{ $num($pendingDischarge->max()) }} m&sup3;/s
                @elseif ($pendingDischarge->last() < $pendingDischarge->first() * 0.95)
                    ease from about {{ $num($pendingDischarge->first()) }} toward {{ $num($pendingDischarge->min()) }} m&sup3;/s
                @else
                    hold near <strong>{{ $num($pendingDischarge->avg()) }} m&sup3;/s</strong>
                @endif
                over the next {{ $pendingDischarge->count() }} days &mdash; how that translates to flood risk needs the reach's calibrated thresholds.
            </p>
        </section>
    @endif
@elseif ($peakScore === null)
    <section class="section-card p-5 step-accent-none">
        <h3 class="font-semibold text-gray-800 dark:text-gray-200 mb-2">No river-flood forecast for {{ $region->name }} yet</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400">
            GloFAS models discharge on mapped river reaches — a heads-up here needs this LGA to sit on
            one, and a forecast to have been pulled and scored. If it does, it will appear after the next
            forecast run.
        </p>
    </section>
@else
    {{-- 1 — The forecast --}}
    <section class="section-card p-5">
        <div class="flex items-baseline gap-3 mb-3">
            <span class="step-badge">1</span>
            <h3 class="font-semibold text-gray-800 dark:text-gray-200">The forecast for {{ $region->name }}</h3>
        </div>
        <p class="text-sm text-gray-700 dark:text-gray-300">
            River flow is forecast to
            @if ($peakDischarge !== null && $firstDischarge !== null && $peakDischarge > $firstDischarge * 1.05)
                rise from about {{ $num($firstDischarge) }} to a peak near <strong>{{ $num($peakDischarge) }} m&sup3;/s</strong>
            @elseif ($peakDischarge !== null && $firstDischarge !== null && $peakDischarge < $firstDischarge * 0.95)
                ease from about {{ $num($firstDischarge) }} toward {{ $num($peakDischarge) }} m&sup3;/s
            @else
                hold near <strong>{{ $num($peakDischarge ?? $firstDischarge) }} m&sup3;/s</strong>
            @endif
            over the next {{ $forecastDaily->count() }} days.
        </p>
        <p class="mt-2 text-xs text-slate-400">
            GloFAS forecast issued {{ $issued?->format('M j, Y') }} &middot; measured against this LGA's normal-flow range.
        </p>
    </section>

    {{-- 2 — The peak --}}
    <section class="score-hero score-hero-{{ $band }}">
        <div class="flex items-center gap-3 mb-3">
            <span class="step-badge">2</span>
            <h3 class="font-semibold text-white/95">The forecast peak</h3>
        </div>
        <div class="flex flex-wrap items-end gap-x-5 gap-y-2">
            <span class="score-hero-number">{{ (int) round($peakScore) }}</span>
            <span class="score-hero-pill mb-1.5">{{ $bandPlain }}</span>
            <span class="mb-1.5 text-sm text-white/85">
                @if ($lead === 0)
                    forecast for today
                @elseif ($lead === 1)
                    forecast for tomorrow
                @else
                    forecast for {{ $peakDate?->format('M j') }}, about {{ $lead }} days out
                @endif
            </span>
        </div>
        @if (($forecastReaches ?? collect())->count() > 1 && $drivingRiver)
            @php
                $calm = $forecastReaches->first(fn ($x) => $x['river'] !== $drivingRiver);
                $calmWord = $calm ? ['green' => 'normal', 'amber' => 'moderate', 'red' => 'high', 'none' => '—'][$calm['band']] : null;
            @endphp
            <p class="mt-3 text-sm text-white/90">
                The <strong>{{ $drivingRiver }}</strong> is driving this.
                @if ($calm) The {{ $calm['river'] }} is at {{ $calm['score'] }}, {{ $calmWord }}. @endif
            </p>
        @endif
        @if (! empty($forecastProbabilityLine))
            <p class="mt-3 text-sm text-white/90"><strong>{{ $forecastProbabilityLine }}</strong></p>
            <p class="mt-1 text-xs text-white/70">
                The chance is worked out by running the forecast about 50 times with slightly different starting conditions and counting how many cross the line.
            </p>
        @endif
        <p class="mt-3 text-xs text-white/70">
            0&ndash;100 scale. Green below 34, amber 34&ndash;66, red 67 and above.
            @if ($region->population !== null) &middot; Population {{ number_format($region->population) }}. @endif
            &middot; This is a forecast, not a current reading.
        </p>
        @if ($calibrationNote)
            <p class="mt-1.5 text-xs text-white/60 italic">{{ $calibrationNote }}</p>
        @endif
    </section>

    {{-- By river — a confluence/valley LGA is scored per named reach (T4/T5 follow-up) --}}
    @if (($forecastReaches ?? collect())->count() > 1)
        <section class="section-card p-5">
            <h3 class="font-semibold text-gray-800 dark:text-gray-200 mb-3">By river</h3>
            <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($forecastReaches->sortByDesc('score') as $r)
                    <li class="flex items-center gap-3 py-2.5">
                        <span class="w-24 text-sm font-medium text-slate-900 dark:text-white">{{ $r['river'] }}</span>
                        @php $bandWord = ['green' => 'low', 'amber' => 'moderate', 'red' => 'high', 'none' => '—'][$r['band']]; @endphp
                        <span class="risk-badge risk-badge-{{ $r['band'] }}">{{ $r['score'] }} · {{ $bandWord }}</span>
                        <span class="flex-1 text-right text-xs text-slate-500 dark:text-slate-400">
                            @if (($r['lead_days'] ?? null) === 0)
                                peak today
                            @elseif (($r['lead_days'] ?? null) === 1)
                                peak tomorrow
                            @elseif (! empty($r['peak_date']))
                                peak {{ \Illuminate\Support\Carbon::parse($r['peak_date'])->format('M j') }} · {{ $r['lead_days'] }}d out
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-xs text-slate-400">
                Each river's forecast discharge measured against that reach's own flood levels. The headline above is the worst of them.
            </p>
        </section>
    @endif

    {{-- 3 — The daily outlook --}}
    <section class="section-card p-5">
        <div class="flex items-baseline gap-3 mb-3">
            <span class="step-badge">3</span>
            <h3 class="font-semibold text-gray-800 dark:text-gray-200">The next {{ $forecastDaily->count() }} days</h3>
        </div>
        <div class="overflow-x-auto">
            <svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full" style="max-width: {{ $w }}px;">
                @if ($fanPolygon !== '')
                    <polygon points="{{ $fanPolygon }}" fill="#0D9488" fill-opacity="0.14" />
                @endif
                <line x1="{{ $pad }}" y1="{{ $h - $pad - (67 / 100) * ($h - 2 * $pad) }}" x2="{{ $w - $pad }}" y2="{{ $h - $pad - (67 / 100) * ($h - 2 * $pad) }}" stroke="#DC2626" stroke-width="1" stroke-dasharray="3 3" stroke-opacity="0.5" />
                <line x1="{{ $pad }}" y1="{{ $h - $pad - (34 / 100) * ($h - 2 * $pad) }}" x2="{{ $w - $pad }}" y2="{{ $h - $pad - (34 / 100) * ($h - 2 * $pad) }}" stroke="#D97706" stroke-width="1" stroke-dasharray="3 3" stroke-opacity="0.5" />
                <polyline points="{{ $line }}" fill="none" stroke="#0D9488" stroke-width="2.5" stroke-linejoin="round" />
                @foreach ($coords as $c)
                    <circle cx="{{ $c['x'] }}" cy="{{ $c['y'] }}" r="{{ $c['d']['date'] === optional($peakDate)->toDateString() ? 4 : 2.5 }}"
                            fill="{{ $c['d']['date'] === optional($peakDate)->toDateString() ? '#0F766E' : '#0D9488' }}" />
                @endforeach
            </svg>
            <div class="flex justify-between text-xs text-gray-500 mt-1">
                <span>{{ Carbon::parse($pts->first()['date'])->format('M j') }}</span>
                <span>{{ Carbon::parse($pts->last()['date'])->format('M j') }}</span>
            </div>
        </div>
        <p class="mt-2 text-xs text-slate-400">
            Dashed lines mark the amber (34) and red (67) bands.
            @if ($fanPolygon !== '') The shaded band is the ensemble's 10th–90th percentile range. @endif
        </p>
    </section>

    {{-- 4 — What to do --}}
    @if ($recommendedAction)
        <section class="section-card p-5 step-accent-{{ $band }}">
            <div class="flex items-baseline gap-3 mb-3">
                <span class="step-badge">4</span>
                <h3 class="font-semibold text-gray-800 dark:text-gray-200">What to do</h3>
            </div>
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
            <p class="mt-2 text-xs text-slate-400">Acting on a forecast — the lead time above is the window to prepare in.</p>
        </section>
    @endif
@endif
