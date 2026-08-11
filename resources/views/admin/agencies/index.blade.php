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
                                <th class="px-4 py-3 whitespace-nowrap">Members</th>
                                <th class="px-4 py-3 whitespace-nowrap">Platform Overview</th>
                                <th class="px-4 py-3 whitespace-nowrap"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($agencies as $agency)
                                <tr>
                                    <td class="px-4 py-3">
                                        <form method="POST" action="{{ route('admin.agencies.update', $agency) }}" class="flex items-center gap-2">
                                            @csrf @method('PATCH')
                                            <x-text-input type="text" name="name" value="{{ $agency->name }}" class="text-sm !py-1.5" />
                                            <x-loading-button class="btn-secondary flex-shrink-0" loading-text="Saving…">Save</x-loading-button>
                                        </form>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $agency->type ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $agency->users_count }}</td>
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
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No agencies yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <x-form-section title="Merge duplicate agencies" last="true"
                description="Every user, saved view, report, and threshold belonging to the duplicate moves to the one you're keeping, then the duplicate is deleted. This can't be undone.">
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
