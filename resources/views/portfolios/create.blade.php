<x-app-layout title="New Portfolio">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('New Portfolio') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
            <div class="section-card p-6">
                <form method="POST" action="{{ route('portfolios.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="name" value="Name" />
                        <p class="text-xs text-slate-400 mb-1">e.g. "Agricultural loan book — North West" or "Tower network — Lagos"</p>
                        <x-text-input id="name" name="name" class="block mt-1 w-full" :value="old('name')" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="type" value="Type" />
                        <p class="text-xs text-slate-400 mb-1">A point list ranks locations independently. A corridor (a route — a road, a pipeline) keeps them in order and reads them as one path — see Module 8.</p>
                        <x-select-input id="type" name="type" class="block mt-1 w-full" required>
                            <option value="point_list" selected>Point list</option>
                            <option value="corridor">Corridor (ordered route)</option>
                        </x-select-input>
                        <x-input-error :messages="$errors->get('type')" class="mt-2" />
                    </div>

                    <div class="flex items-center justify-end gap-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                        <a href="{{ route('portfolios.index') }}" class="text-sm text-gray-600 dark:text-gray-400 hover:underline">Cancel</a>
                        <x-loading-button class="btn-primary text-xs uppercase tracking-widest" loading-text="Creating…">Create portfolio</x-loading-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
