<x-app-layout title="Users">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Users') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Every registered account. Grant admin sparingly — an admin can do this to anyone except themselves.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <form method="GET" action="{{ route('admin.users.index') }}" class="section-card p-4">
                <x-text-input type="search" name="search" :value="$search" placeholder="Search by name or email..." class="w-full" />
            </form>

            <div class="section-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">Email</th>
                                <th class="px-4 py-3">Organization</th>
                                <th class="px-4 py-3">Role</th>
                                <th class="px-4 py-3">State</th>
                                <th class="px-4 py-3">Admin</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($users as $user)
                                <tr>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $user->email }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $user->agency?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $user->designation ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $user->state ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm">
                                        <span class="risk-badge {{ $user->isPlatformAdmin() ? 'risk-badge-green' : 'risk-badge-none' }}">
                                            {{ $user->isPlatformAdmin() ? 'admin' : 'no' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        <span class="risk-badge {{ $user->isDisabled() ? 'risk-badge-red' : 'risk-badge-green' }}">
                                            {{ $user->isDisabled() ? 'deactivated' : 'active' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-right whitespace-nowrap">
                                        @if ($user->id === auth()->id())
                                            <span class="text-xs text-slate-400">that's you</span>
                                        @else
                                            <form method="POST" action="{{ route('admin.users.toggle-admin', $user) }}" class="inline">
                                                @csrf @method('PATCH')
                                                <button type="submit" class="btn-secondary">
                                                    {{ $user->isPlatformAdmin() ? 'Revoke admin' : 'Make admin' }}
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}" class="inline">
                                                @csrf @method('PATCH')
                                                <button type="submit" class="{{ $user->isDisabled() ? 'btn-secondary' : 'btn-danger' }}">
                                                    {{ $user->isDisabled() ? 'Reactivate' : 'Deactivate' }}
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500">No users match that search.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div>{{ $users->links() }}</div>
        </div>
    </div>
</x-app-layout>
