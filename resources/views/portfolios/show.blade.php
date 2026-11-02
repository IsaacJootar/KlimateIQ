<x-app-layout :title="$portfolio->name">
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ $portfolio->name }}
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $portfolio->isCorridor() ? 'Corridor — read in order' : 'Point list — ranked worst to best' }}
                    &middot; {{ $rows->count() }} location{{ $rows->count() === 1 ? '' : 's' }}
                </p>
            </div>
            <form method="POST" action="{{ route('portfolios.destroy', $portfolio) }}"
                  onsubmit="return confirm('Delete this portfolio? This cannot be undone.');">
                @csrf @method('DELETE')
                <button type="submit" class="text-xs text-red-600 hover:underline">Delete portfolio</button>
            </form>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <x-form-section title="Add a location" last="true">
                <form method="POST" action="{{ route('portfolios.locations.store', $portfolio) }}" x-data="{ mode: 'region' }" class="space-y-4">
                    @csrf
                    <div class="flex gap-4 text-sm">
                        <label class="flex items-center gap-1.5">
                            <input type="radio" x-model="mode" value="region"> From the LGA list
                        </label>
                        <label class="flex items-center gap-1.5">
                            <input type="radio" x-model="mode" value="coordinate"> Raw coordinate
                        </label>
                    </div>

                    <div x-show="mode === 'region'">
                        <x-input-label for="region_id" value="LGA" />
                        <x-select-input id="region_id" name="region_id" class="block mt-1 w-full">
                            <option value="">Select an LGA</option>
                            @foreach ($regions as $region)
                                <option value="{{ $region->region_id }}">{{ $region->name }} ({{ $region->state }})</option>
                            @endforeach
                        </x-select-input>
                    </div>

                    <div x-show="mode === 'coordinate'" x-cloak class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <x-input-label for="latitude" value="Latitude" />
                            <x-text-input id="latitude" name="latitude" type="text" class="block mt-1 w-full" placeholder="6.5244" />
                        </div>
                        <div>
                            <x-input-label for="longitude" value="Longitude" />
                            <x-text-input id="longitude" name="longitude" type="text" class="block mt-1 w-full" placeholder="3.3792" />
                        </div>
                        <div>
                            <x-input-label for="label" value="Label" />
                            <x-text-input id="label" name="label" type="text" class="block mt-1 w-full" placeholder="Tower #42, Ikeja axis" />
                        </div>
                        <p class="sm:col-span-3 text-xs text-amber-600 dark:text-amber-400">
                            There's no scoring data for this exact point — a raw coordinate is scored against the nearest LGA instead, clearly marked below with the distance. If nothing seeded is within {{ \App\Support\NearestRegion::MAX_USEFUL_DISTANCE_KM }} km, it shows "not scorable" rather than guessing from something too far away.
                        </p>
                    </div>

                    <x-input-error :messages="$errors->get('region_id')" class="mt-2" />
                    <x-input-error :messages="$errors->get('latitude')" class="mt-2" />

                    <x-loading-button class="btn-secondary" loading-text="Adding…">Add location</x-loading-button>
                </form>
            </x-form-section>

            <div class="section-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3">Location</th>
                                <th class="px-4 py-3">Worst index</th>
                                <th class="px-4 py-3">Score</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($rows as $row)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-200">
                                        {{ $row['display_name'] }}
                                        @if ($row['approximated'])
                                            <div class="text-xs font-normal text-amber-600 dark:text-amber-400 mt-0.5">
                                                &asymp; nearest LGA: {{ $row['nearest_region_name'] }} ({{ $row['distance_km'] }} km away)
                                            </div>
                                        @endif
                                    </td>
                                    @if ($row['scorable'])
                                        <td class="px-4 py-3 text-sm text-gray-500">
                                            {{ $row['worst_index_name'] ?? 'No score yet' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            @if ($row['worst_score'] !== null)
                                                <span class="risk-badge risk-badge-{{ $row['worst_band'] }}">{{ round($row['worst_score']) }}</span>
                                            @else
                                                <span class="text-xs text-gray-400">No score yet</span>
                                            @endif
                                        </td>
                                    @else
                                        <td colspan="2" class="px-4 py-3 text-xs text-amber-600 dark:text-amber-400">
                                            {{ $row['reason'] }}
                                        </td>
                                    @endif
                                    <td class="px-4 py-3 text-right">
                                        <form method="POST" action="{{ route('portfolios.locations.destroy', [$portfolio, $row['location']]) }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-xs text-red-600 hover:underline">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">
                                        No locations yet — add one above.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
