<x-app-layout title="Pipeline Health">
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ __('Pipeline Health') }}
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    When each active region last got real data from each source, and anything that's failed.
                </p>
            </div>
            <form method="POST" action="{{ route('admin.pipeline.run-now') }}" x-data="{ loading: false }" @submit="loading = true">
                @csrf
                <button type="submit" class="btn-primary flex-shrink-0" x-bind:disabled="loading">
                    <span x-show="! loading">Run ingestion now</span>
                    <span x-show="loading" x-cloak class="inline-flex items-center gap-1.5">
                        <svg class="animate-spin h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Queuing jobs&hellip;
                    </span>
                </button>
            </form>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Data freshness</h3>
                    <span class="text-xs text-slate-500 dark:text-slate-400">
                        <span class="risk-badge risk-badge-red">never</span> = no successful pull yet &middot;
                        <span class="risk-badge risk-badge-amber">amber</span> = older than {{ 10 }} days &middot;
                        <span class="risk-badge risk-badge-green">green</span> = fresh
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3">Region</th>
                                @foreach ($sources as $source)
                                    <th class="px-4 py-3">{{ $source->signalTypeCode() }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($grid as $row)
                                <tr>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $row['region']->name }}</td>
                                    @foreach ($row['cells'] as $cell)
                                        <td class="px-4 py-3 text-sm">
                                            @if ($cell['ingested_at'])
                                                <span class="risk-badge {{ $cell['stale'] ? 'risk-badge-amber' : 'risk-badge-green' }}">
                                                    {{ $cell['ingested_at']->diffForHumans() }}
                                                </span>
                                            @else
                                                <span class="risk-badge risk-badge-red">never</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $sources->count() + 1 }}" class="px-4 py-8 text-center text-sm text-gray-500">No active regions yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Recent failures</h3>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($failures as $failure)
                        <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">
                                    {{ $failure['source'] ?? 'Unknown source' }}
                                    <span class="font-normal text-slate-400">in</span>
                                    {{ $failure['region'] ?? 'Unknown region' }}
                                </p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ $failure['message'] }} &middot; {{ $failure['failed_at']->diffForHumans() }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('admin.pipeline.failures.retry', $failure['uuid']) }}" class="flex-shrink-0"
                                  x-data="{ loading: false }" @submit="loading = true">
                                @csrf
                                <button type="submit" class="btn-secondary" x-bind:disabled="loading">
                                    <span x-show="! loading">Retry</span>
                                    <span x-show="loading" x-cloak>Retrying&hellip;</span>
                                </button>
                            </form>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-gray-500">No failures — the pipeline has been running clean.</li>
                    @endforelse
                </ul>
            </div>

        </div>
    </div>
</x-app-layout>
