<x-app-layout title="Saved Views">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Saved Views') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Save a specific index + region combination so you don't have to reconfigure it every visit.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="section-card p-6">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Save a view</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">e.g. "My malaria watchlist — 5 LGAs" — pick an index and the regions you actually cover, then reload it any time from below.</p>

                <form method="POST" action="{{ route('saved-views.store') }}" class="space-y-5">
                    @csrf
                    <div>
                        <x-input-label for="name">Name</x-input-label>
                        <x-text-input id="name" type="text" name="name" required maxlength="150" placeholder="My malaria watchlist" />
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="index_id">Index</x-input-label>
                            <x-select-input id="index_id" name="index_id">
                                @foreach ($indices as $idx)
                                    <option value="{{ $idx->index_id }}">{{ $idx->name }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <div>
                            <x-input-label for="region_ids">Regions</x-input-label>
                            <x-select-input id="region_ids" name="region_ids[]" multiple required size="5">
                                @foreach ($regions as $region)
                                    <option value="{{ $region->region_id }}">{{ $region->name }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                    </div>
                    @if ($hasAgency)
                        <div>
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input type="checkbox" name="share_with_agency" value="1" class="rounded">
                                Share with everyone in my agency
                            </label>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                Everyone registered under {{ auth()->user()->agency?->name ?? 'your agency' }} will be able to see and use this saved view. Leave unchecked to keep it private to you.
                            </p>
                        </div>
                    @endif
                    <x-loading-button class="btn-primary w-full sm:w-auto" loading-text="Saving…">Save view</x-loading-button>
                </form>
            </div>

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Your saved views</h3>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($savedViews as $view)
                        <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">
                                    {{ $view->name }}
                                    @if ($view->isShared())
                                        <span class="risk-badge risk-badge-green ms-1">agency</span>
                                    @endif
                                </p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ $view->index?->name ?? 'All indices' }} &middot; {{ count($view->region_ids ?? []) }} regions
                                </p>
                            </div>
                            <div class="flex items-center gap-3 flex-shrink-0">
                                <a href="{{ route('regions.index', ['index' => $view->index?->code, 'regions' => implode(',', $view->region_ids ?? [])]) }}" class="link-nav">Load &rarr;</a>
                                <form method="POST" action="{{ route('saved-views.destroy', $view) }}" class="inline">
                                    @csrf @method('DELETE')
                                    <x-loading-button class="btn-danger" loading-text="Deleting…">Delete</x-loading-button>
                                </form>
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-gray-500">No saved views yet.</li>
                    @endforelse
                </ul>
            </div>

            @if ($hasAgency)
                <div class="section-card overflow-hidden">
                    <div class="section-card-header">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">Shared by your agency</h3>
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($sharedViews as $view)
                            <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 py-4">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $view->name }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                        {{ $view->index?->name ?? 'All indices' }} &middot; {{ count($view->region_ids ?? []) }} regions &middot; saved by {{ $view->user->name }}
                                    </p>
                                </div>
                                <a href="{{ route('regions.index', ['index' => $view->index?->code, 'regions' => implode(',', $view->region_ids ?? [])]) }}" class="link-nav flex-shrink-0">Load &rarr;</a>
                            </li>
                        @empty
                            <li class="px-5 py-8 text-center text-sm text-gray-500">Nobody in your agency has shared a view yet.</li>
                        @endforelse
                    </ul>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
