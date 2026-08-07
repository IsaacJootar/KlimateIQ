<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Alerts') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 p-4 rounded-lg bg-green-50 dark:bg-green-900 text-green-700 dark:text-green-200 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <div class="section-card">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3">Region</th>
                            <th class="px-4 py-3">Watching</th>
                            <th class="px-4 py-3">Value</th>
                            <th class="px-4 py-3">Threshold</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Triggered</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($alerts as $alert)
                            <tr>
                                <td class="px-4 py-3 text-sm">{{ $alert->region->name }}</td>
                                <td class="px-4 py-3 text-sm">{{ $alert->index?->name ?? $alert->signalType?->name }}</td>
                                <td class="px-4 py-3 text-sm">{{ $alert->score_at_trigger }}</td>
                                <td class="px-4 py-3 text-sm">{{ $alert->threshold_value ?? 'anomaly baseline' }}</td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="inline-flex px-2 py-1 rounded-full text-xs font-semibold
                                        {{ $alert->status === 'OPEN' ? 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200' : '' }}
                                        {{ $alert->status === 'ACKNOWLEDGED' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200' : '' }}
                                        {{ $alert->status === 'RESOLVED' ? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' : '' }}">
                                        {{ $alert->status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500">{{ $alert->triggered_at->diffForHumans() }}</td>
                                <td class="px-4 py-3 text-sm text-right space-x-2">
                                    @if ($alert->status === 'OPEN')
                                        <form method="POST" action="{{ route('alerts.acknowledge', $alert) }}" class="inline">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="btn-secondary">Acknowledge</button>
                                        </form>
                                    @endif
                                    @if ($alert->status !== 'RESOLVED')
                                        <form method="POST" action="{{ route('alerts.resolve', $alert) }}" class="inline">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="btn-primary">Resolve</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500">No alerts yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $alerts->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
