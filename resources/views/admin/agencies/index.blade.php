<x-app-layout title="Agencies">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Agencies') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Every organization registered on the platform, with how many accounts belong to it.
            "Platform Overview" grants every account under that agency a cross-agency view of the
            whole platform — not just their own configured coverage. Reserve it for organizations
            whose actual mission is oversight across everyone, like a national public health body.
        </p>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            <strong>Portfolio Type</strong> sets which indices an agency sees by default
            (<code>App\Support\PortfolioType</code>) — expand "Indices" on a row to override
            individual ones regardless of the type default.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="section-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3 whitespace-nowrap">Name</th>
                                <th class="px-4 py-3 whitespace-nowrap">Type</th>
                                <th class="px-4 py-3 whitespace-nowrap">Portfolio Type</th>
                                <th class="px-4 py-3 whitespace-nowrap">Members</th>
                                <th class="px-4 py-3 whitespace-nowrap">Indices</th>
                                <th class="px-4 py-3 whitespace-nowrap">Platform Overview</th>
                                <th class="px-4 py-3 whitespace-nowrap"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700" x-data="{ expandedAgency: null }">
                            @forelse ($agencies as $agency)
                                <tr>
                                    <td class="px-4 py-3">
                                        <form method="POST" action="{{ route('admin.agencies.update', $agency) }}" class="flex items-center gap-2">
                                            @csrf @method('PATCH')
                                            <x-text-input type="text" name="name" value="{{ $agency->name }}" class="text-sm !py-1.5" />
                                            <select name="portfolio_type" class="text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 !py-1.5">
                                                <option value="">— none —</option>
                                                @foreach ($portfolioTypes as $type)
                                                    <option value="{{ $type->value }}" @selected($agency->portfolio_type === $type)>{{ $type->label() }}</option>
                                                @endforeach
                                            </select>
                                            <x-loading-button class="btn-secondary flex-shrink-0" loading-text="Saving…">Save</x-loading-button>
                                        </form>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $agency->type ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $agency->portfolio_type?->label() ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $agency->users_count }}</td>
                                    <td class="px-4 py-3 text-sm">
                                        <button type="button" @click="expandedAgency = (expandedAgency === '{{ $agency->agency_id }}' ? null : '{{ $agency->agency_id }}')" class="link-nav text-xs">
                                            <span x-text="expandedAgency === '{{ $agency->agency_id }}' ? 'Hide' : '{{ $visibleIndexIds[$agency->agency_id]->count() }} visible'"></span>
                                        </button>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="risk-badge {{ $agency->federal_oversight ? 'risk-badge-green' : 'risk-badge-none' }}">
                                            {{ $agency->federal_oversight ? 'granted' : 'off' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <form method="POST" action="{{ route('admin.agencies.toggle-oversight', $agency) }}">
                                            @csrf @method('PATCH')
                                            <x-loading-button class="btn-secondary" loading-text="Updating…">
                                                {{ $agency->federal_oversight ? 'Revoke' : 'Grant' }}
                                            </x-loading-button>
                                        </form>
                                    </td>
                                </tr>
                                <tr x-show="expandedAgency === '{{ $agency->agency_id }}'" x-cloak>
                                    <td colspan="7" class="px-4 py-3 bg-gray-50 dark:bg-gray-900/40">
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                                            Toggling an index here overrides the Portfolio Type default for
                                            <strong>{{ $agency->name }}</strong> only. Toggling it back to what the
                                            default would already show removes the override.
                                        </p>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($indices as $index)
                                                @php $visible = $visibleIndexIds[$agency->agency_id]->contains($index->index_id); @endphp
                                                <form method="POST" action="{{ route('admin.agencies.toggle-index', $agency) }}">
                                                    @csrf @method('PATCH')
                                                    <input type="hidden" name="index_id" value="{{ $index->index_id }}">
                                                    <button type="submit" class="text-xs px-2.5 py-1 rounded-full border {{ $visible ? 'bg-gano-100 border-gano-300 text-gano-800 dark:bg-gano-900/40 dark:border-gano-700 dark:text-gano-200' : 'bg-gray-100 border-gray-300 text-gray-400 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-500' }}">
                                                        {{ $index->name }}
                                                    </button>
                                                </form>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500">No agencies yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <x-form-section title="Merge duplicate agencies" last="true"
                description="Every user, saved view, report, threshold, and index override belonging to the duplicate moves to the one you're keeping, then the duplicate is deleted. This can't be undone.">
                <form method="POST" action="{{ route('admin.agencies.merge') }}"
                      onsubmit="return confirm('Merge these agencies? Every member and shared item moves to the one you keep, and the other is permanently deleted.');"
                      class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @csrf
                    <div>
                        <x-input-label for="duplicate_agency_id">Duplicate (will be deleted)</x-input-label>
                        <x-select-input id="duplicate_agency_id" name="duplicate_agency_id" required>
                            <option value="">Select an agency</option>
                            @foreach ($agencies as $agency)
                                <option value="{{ $agency->agency_id }}">{{ $agency->name }} ({{ $agency->users_count }} members)</option>
                            @endforeach
                        </x-select-input>
                    </div>
                    <div>
                        <x-input-label for="keep_agency_id">Keep</x-input-label>
                        <x-select-input id="keep_agency_id" name="keep_agency_id" required>
                            <option value="">Select an agency</option>
                            @foreach ($agencies as $agency)
                                <option value="{{ $agency->agency_id }}">{{ $agency->name }} ({{ $agency->users_count }} members)</option>
                            @endforeach
                        </x-select-input>
                    </div>
                    <x-loading-button class="btn-danger w-full sm:w-auto sm:col-span-2" loading-text="Merging…">Merge agencies</x-loading-button>
                </form>
            </x-form-section>

        </div>
    </div>
</x-app-layout>
