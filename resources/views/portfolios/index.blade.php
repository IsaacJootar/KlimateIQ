<x-app-layout title="Portfolios">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Portfolios') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            A named list of locations — a loan book, a site list, a covered territory — scored
            and ranked worst-to-best in one view. Shared across everyone in your organization.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (! $hasAgency)
                <div class="section-card p-5 text-sm text-slate-500 dark:text-slate-400">
                    Your account isn't part of an organization yet, so there's nowhere to keep a
                    portfolio. Add or select an organization from your <a href="{{ route('profile.edit') }}" class="link-nav">profile</a>.
                </div>
            @else
                <div class="flex justify-end">
                    <a href="{{ route('portfolios.create') }}" class="btn-primary text-xs uppercase tracking-widest">New portfolio</a>
                </div>

                <div class="section-card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">Type</th>
                                    <th class="px-4 py-3">Locations</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @forelse ($portfolios as $portfolio)
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-200">{{ $portfolio->name }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-500">{{ $portfolio->isCorridor() ? 'Corridor' : 'Point list' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-500">{{ $portfolio->locations_count }}</td>
                                        <td class="px-4 py-3 text-right">
                                            <a href="{{ route('portfolios.show', $portfolio) }}" class="link-nav text-sm">Open &rarr;</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">
                                            No portfolios yet — <a href="{{ route('portfolios.create') }}" class="link-nav">create one</a> to get started.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
