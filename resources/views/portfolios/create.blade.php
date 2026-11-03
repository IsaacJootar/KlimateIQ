<x-app-layout title="New Portfolio">
    <x-slot name="header">
        <p class="text-xs font-semibold uppercase tracking-widest text-primary">Your Portfolio</p>
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Protect a new portfolio') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if ($agency->portfolio_type)
                <div class="flex items-center gap-2 rounded-lg border border-primary/20 bg-primary/5 dark:bg-primary/10 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                    Setting this up for <span class="font-semibold">{{ $agency->name }}</span>
                    &mdash; <span class="font-semibold">{{ $agency->portfolio_type->label() }}</span>
                </div>
            @endif

            <div class="section-card p-6">
                <form method="POST" action="{{ route('portfolios.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="name" value="What are you protecting?" />
                        <p class="text-xs text-slate-400 mb-1">Give this book of assets a name — e.g. "Agricultural loan book — North West" or "Tower network — Lagos".</p>
                        <x-text-input id="name" name="name" class="block mt-1 w-full" :value="old('name')" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="type" value="How are the assets arranged?" />
                        <p class="text-xs text-slate-400 mb-1">A point list ranks each asset independently, worst-to-best. A corridor keeps a route — a road, a pipeline — in order and reads it as one path.</p>
                        <x-select-input id="type" name="type" class="block mt-1 w-full" required>
                            <option value="point_list" selected>Point list — independent assets</option>
                            <option value="corridor">Corridor — an ordered route</option>
                        </x-select-input>
                        <x-input-error :messages="$errors->get('type')" class="mt-2" />
                    </div>

                    <div class="flex items-center justify-end gap-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                        <a href="{{ route('portfolios.index') }}" class="text-sm text-gray-600 dark:text-gray-400 hover:underline">Cancel</a>
                        <x-loading-button class="btn-primary text-xs uppercase tracking-widest" loading-text="Setting up…">Start protecting this portfolio</x-loading-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
