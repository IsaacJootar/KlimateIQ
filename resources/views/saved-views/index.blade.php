<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Saved Views') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="p-4 rounded-lg bg-green-50 dark:bg-green-900 text-green-700 dark:text-green-200 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <div class="section-card p-6">
                <h3 class="font-semibold text-gray-800 dark:text-gray-200 mb-4">Save a view</h3>
                <p class="text-sm text-gray-500 mb-4">e.g. "My malaria watchlist — 5 LGAs" — pick an index and the regions you actually cover, then reload it any time from below.</p>
                <form method="POST" action="{{ route('saved-views.store') }}" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @csrf
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium mb-1">Name</label>
                        <input type="text" name="name" required maxlength="150" placeholder="My malaria watchlist" class="w-full rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Index</label>
                        <select name="index_id" class="w-full rounded-lg">
                            @foreach ($indices as $idx)
                                <option value="{{ $idx->index_id }}">{{ $idx->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Regions</label>
                        <select name="region_ids[]" multiple required class="w-full rounded-lg" size="5">
                            @foreach ($regions as $region)
                                <option value="{{ $region->region_id }}">{{ $region->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="btn-primary">Save view</button>
                    </div>
                </form>
            </div>

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Your saved views</h3>
                </div>
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Index</th>
                            <th class="px-4 py-3">Regions</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($savedViews as $view)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium">{{ $view->name }}</td>
                                <td class="px-4 py-3 text-sm">{{ $view->index?->name ?? 'All' }}</td>
                                <td class="px-4 py-3 text-sm">{{ count($view->region_ids ?? []) }} regions</td>
                                <td class="px-4 py-3 text-sm text-right space-x-2">
                                    <a href="{{ route('regions.index', ['index' => $view->index?->code, 'regions' => implode(',', $view->region_ids ?? [])]) }}" class="link-nav">Load &rarr;</a>
                                    <form method="POST" action="{{ route('saved-views.destroy', $view) }}" class="inline">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">No saved views yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
