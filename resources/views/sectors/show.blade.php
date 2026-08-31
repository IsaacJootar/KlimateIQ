@php
    $anyAttention = $attentionCount > 0;
    $heroBand = $anyAttention ? 'amber' : 'green';
@endphp

<x-app-layout :title="$sector->name">
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ $sector->name }}
            </h2>
            <a href="{{ route('dashboard') }}" class="link-nav">Dashboard &rarr;</a>
        </div>
        @if ($sector->description)
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $sector->description }}</p>
        @endif
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">

            @unless ($followed)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gano-200 dark:border-gano-800 bg-gano-50 dark:bg-gano-950/50 px-4 py-3 text-sm">
                    <span class="text-gano-900 dark:text-gano-100">You don't follow {{ $sector->name }} yet — it won't show on your dashboard.</span>
                    <a href="{{ route('coverage.edit') }}" class="link-nav shrink-0">Add it in Workspace &rarr;</a>
                </div>
            @endunless

            {{-- Headline --}}
            <section class="score-hero score-hero-{{ $heroBand }}">
                <h3 class="font-semibold text-white/95 mb-1">This week across {{ $sector->name }}</h3>
                @if ($regionCount === 0)
                    <p class="text-lg font-semibold">No regions in your coverage yet.</p>
                @elseif ($anyAttention)
                    <p class="text-2xl font-bold leading-tight">
                        @if ($hasCoverage)
                            {{ $attentionCount }} of your {{ $regionCount }} LGAs need attention
                        @else
                            {{ $attentionCount }} of {{ $regionCount }} active LGAs need attention
                        @endif
                    </p>
                    <p class="mt-1 text-sm text-white/80">Amber or red on at least one {{ strtolower($sector->name) }} index.</p>
                @else
                    <p class="text-2xl font-bold leading-tight">
                        All {{ $regionCount }} {{ $regionCount === 1 ? 'LGA is' : 'LGAs are' }} clear
                    </p>
                    <p class="mt-1 text-sm text-white/80">No {{ strtolower($sector->name) }} index is amber or red this week.</p>
                @endif
            </section>

            {{-- Index cards --}}
            @if ($cards->isEmpty())
                <div class="section-card p-6 text-center text-sm text-slate-500">
                    This sector has no indices yet.
                </div>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach ($cards as $card)
                        <a href="{{ route('regions.index', ['index' => $card['index']->code]) }}"
                           class="section-card nav-card p-5 flex flex-col gap-2.5">
                            <div class="flex items-start justify-between gap-3">
                                <span class="font-semibold text-slate-900 dark:text-white leading-snug">
                                    {{ str_replace(' Index', '', $card['index']->name) }}
                                </span>
                                <span class="risk-badge risk-badge-{{ $card['band'] }} shrink-0">
                                    {{ $card['band'] === 'none' ? 'no data' : $card['band'] }}
                                </span>
                            </div>

                            @if ($card['scored_count'] === 0)
                                <p class="text-sm text-slate-500">No scored LGAs yet for this index.</p>
                            @else
                                @if ($card['need_attention'] > 0)
                                    <p class="text-sm font-semibold text-slate-900 dark:text-white">
                                        {{ $card['need_attention'] }} of {{ $card['scored_count'] }} {{ $card['scored_count'] === 1 ? 'LGA needs' : 'LGAs need' }} attention
                                    </p>
                                @else
                                    <p class="text-sm text-slate-700 dark:text-slate-300">
                                        All {{ $card['scored_count'] }} {{ $card['scored_count'] === 1 ? 'LGA is' : 'LGAs are' }} clear
                                    </p>
                                @endif

                                @if ($card['worst_region'])
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        Highest: {{ $card['worst_region'] }} ({{ $card['worst_score'] }})
                                    </p>
                                @endif

                                <p class="text-xs
                                    {{ ['up' => 'text-red-600 dark:text-red-400', 'down' => 'text-emerald-600 dark:text-emerald-400'][$card['trend']['direction']] ?? 'text-slate-400' }}">
                                    @if ($card['trend']['direction'] === 'up') &uarr; @elseif ($card['trend']['direction'] === 'down') &darr; @endif
                                    {{ $card['trend']['label'] }}
                                </p>
                            @endif

                            <span class="mt-auto pt-1 text-xs font-semibold text-primary">View all LGAs &rarr;</span>
                        </a>
                    @endforeach
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
