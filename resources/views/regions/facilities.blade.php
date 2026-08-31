@php
    $labels = [
        'health' => 'Health facilities',
        'school' => 'Schools',
        'market' => 'Markets & trading clusters',
        'water_point' => 'Water points',
        'shelter' => 'Shelter-capable sites',
    ];
@endphp

<x-app-layout :title="$region->name.' — facilities'">
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ $region->name }}, {{ $region->state }} — facilities on record
            </h2>
            <a href="{{ route('regions.show', $region->region_id) }}" class="link-nav">&larr; Back to {{ $region->name }}</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">

            <div class="flex gap-2.5 rounded-xl bg-gano-50 dark:bg-gano-950/50 border border-gano-100 dark:border-gano-900 px-3.5 py-2.5">
                <svg class="mt-0.5 h-4 w-4 shrink-0 text-gano-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>
                </svg>
                <p class="text-sm text-gano-900 dark:text-gano-100">
                    From {{ $attribution }}. These are places on record for this LGA, offered as a starting point —
                    confirm which are open and reachable before acting.
                </p>
            </div>

            @forelse ($byType as $type => $facilities)
                <section class="section-card p-5">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200 mb-3">{{ $labels[$type] ?? ucfirst($type) }}</h3>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($facilities as $f)
                            <li class="flex items-baseline justify-between gap-3 py-2">
                                <span class="text-sm text-slate-900 dark:text-white">{{ $f->name }}</span>
                                @if ($f->category)
                                    <span class="text-xs text-slate-500 dark:text-slate-400 shrink-0">{{ $f->category }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <div class="section-card p-6 text-center text-sm text-slate-500">
                    No facilities are on record for {{ $region->name }} yet. Coverage is being widened LGA by LGA.
                </div>
            @endforelse

        </div>
    </div>
</x-app-layout>
