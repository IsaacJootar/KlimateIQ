<x-app-layout title="Exposure">
    <x-slot name="header">
        <p class="text-xs font-semibold uppercase tracking-widest text-primary">Your Portfolio</p>
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Exposure') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            What you're protecting, totaled across every portfolio — not one at a time.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (! $hasAgency)
                <div class="section-card p-5 text-sm text-slate-500 dark:text-slate-400">
                    Your account isn't part of an organization yet, so there's nothing to total.
                </div>
            @elseif ($perPortfolio->isEmpty())
                <div class="section-card p-5 text-sm text-slate-500 dark:text-slate-400">
                    No portfolios yet — <a href="{{ route('portfolios.create') }}" class="link-nav">start one</a> to see its exposure here.
                </div>
            @else
                @if ($combined->isEmpty())
                    <div class="section-card p-5 text-sm text-slate-500 dark:text-slate-400">
                        No asset in any portfolio has a value yet — add one from a portfolio's own page to see totals here.
                    </div>
                @else
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        @foreach ($combined as $summary)
                            <div class="metric-card metric-teal">
                                <div class="metric-card-label">Protected{{ $combined->count() > 1 ? " ({$summary['currency']})" : '' }}</div>
                                <div class="metric-card-value">{{ $summary['symbol'] }}{{ number_format($summary['total']) }}</div>
                                <div class="metric-card-sub">across every portfolio</div>
                            </div>
                            <div class="metric-card metric-red">
                                <div class="metric-card-label">At Risk{{ $combined->count() > 1 ? " ({$summary['currency']})" : '' }}</div>
                                <div class="metric-card-value">{{ $summary['symbol'] }}{{ number_format($summary['atRisk']) }}</div>
                                <div class="metric-card-sub">in red or amber locations</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="section-card overflow-hidden">
                    <div class="section-card-header">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">By portfolio</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                    <th class="px-4 py-3">Portfolio</th>
                                    <th class="px-4 py-3">Protected</th>
                                    <th class="px-4 py-3">At Risk</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @forelse ($perPortfolio as $entry)
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-200">{{ $entry['portfolio']->name }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-500">
                                            @forelse ($entry['summary'] as $summary)
                                                {{ $summary['symbol'] }}{{ number_format($summary['total']) }}@unless ($loop->last) <br> @endunless
                                            @empty
                                                <span class="text-xs text-gray-400">No value entered</span>
                                            @endforelse
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-500">
                                            @forelse ($entry['summary'] as $summary)
                                                {{ $summary['symbol'] }}{{ number_format($summary['atRisk']) }}@unless ($loop->last) <br> @endunless
                                            @empty
                                                &mdash;
                                            @endforelse
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <a href="{{ route('portfolios.show', $entry['portfolio']) }}" class="link-nav text-sm">Open &rarr;</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">No portfolios yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
