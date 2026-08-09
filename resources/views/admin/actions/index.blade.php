@php
    $bandLabels = ['green' => 'Green (low risk)', 'amber' => 'Amber (moderate risk)', 'red' => 'Red (high risk)'];
    $bandBadge = ['green' => 'risk-badge-green', 'amber' => 'risk-badge-amber', 'red' => 'risk-badge-red'];
@endphp

<x-app-layout title="Recommended Actions">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Recommended Actions') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            The plain-English "what to do about it" text shown per index and risk band — on region pages, threshold
            breach alerts, and PDF reports. Rule-based, not AI-generated.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="flex flex-wrap gap-2">
                @foreach ($indices as $idx)
                    <a href="{{ route('admin.actions.index', ['index' => $idx->index_id]) }}"
                       class="pill-tab {{ $idx->index_id === $index->index_id ? 'pill-tab-active' : '' }}">
                        {{ $idx->name }}
                    </a>
                @endforeach
            </div>

            <div class="section-card p-6">
                <form method="POST" action="{{ route('admin.actions.update', $index) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    @foreach ($bands as $band)
                        <div>
                            <label class="flex items-center gap-2 mb-1">
                                <span class="risk-badge {{ $bandBadge[$band] }}">{{ $band }}</span>
                                <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $bandLabels[$band] }}</span>
                            </label>
                            <textarea name="action_text[{{ $band }}]" rows="3"
                                class="w-full px-3.5 py-2.5 text-sm text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 rounded-xl shadow-sm focus:outline-none focus:border-gano-500 focus:ring-2 focus:ring-gano-500/20 transition-shadow duration-150"
                            >{{ old("action_text.{$band}", $actions->get($band)?->action_text) }}</textarea>
                        </div>
                    @endforeach

                    <x-loading-button class="btn-primary w-full sm:w-auto" loading-text="Saving…">Save {{ $index->name }} actions</x-loading-button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
