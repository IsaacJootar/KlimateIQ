<x-app-layout title="Scenario Testing">
    <x-slot name="header">
        <p class="text-xs font-semibold uppercase tracking-widest text-primary">Your Portfolio</p>
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Scenario Testing') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Apply a named stress preset to a portfolio's real, already-computed scores — illustrative,
            not a calibrated forecast. Pick a portfolio below to run one.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (! $hasAgency)
                <div class="section-card p-5 text-sm text-slate-500 dark:text-slate-400">
                    Your account isn't part of an organization yet, so there's no portfolio to stress test.
                </div>
            @elseif ($portfolios->isEmpty())
                <div class="section-card p-5 text-sm text-slate-500 dark:text-slate-400">
                    No portfolios yet — <a href="{{ route('portfolios.create') }}" class="link-nav">start one</a> before running a scenario.
                </div>
            @else
                @foreach ($portfolios as $entry)
                    <a href="{{ route('portfolios.stress-test', $entry['portfolio']) }}" class="section-card p-5 flex items-center justify-between gap-4 hover:border-gano-400 dark:hover:border-gano-500 transition-colors">
                        <div>
                            <h3 class="font-semibold text-gray-800 dark:text-gray-200">{{ $entry['portfolio']->name }}</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $entry['locationCount'] }} {{ Str::plural('location', $entry['locationCount']) }}</p>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            @if ($entry['redCount'] > 0)
                                <span class="risk-badge risk-badge-red">{{ $entry['redCount'] }} red now</span>
                            @endif
                            <span class="link-nav text-sm">Run stress test &rarr;</span>
                        </div>
                    </a>
                @endforeach
            @endif

        </div>
    </div>
</x-app-layout>
