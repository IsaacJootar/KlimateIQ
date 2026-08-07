<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Alerts') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Every threshold breach lands here — acknowledge it to show you've seen it, resolve it once it's handled.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 p-4 rounded-lg bg-green-50 dark:bg-green-900 text-green-700 dark:text-green-200 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <div class="section-card overflow-hidden">
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($alerts as $alert)
                        <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">
                                    {{ $alert->index?->name ?? $alert->signalType?->name }}
                                    <span class="font-normal text-slate-400">in</span>
                                    {{ $alert->region->name }}
                                    <span class="inline-flex ms-1 px-2 py-0.5 rounded-full text-xs font-semibold
                                        {{ $alert->status === 'OPEN' ? 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200' : '' }}
                                        {{ $alert->status === 'ACKNOWLEDGED' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200' : '' }}
                                        {{ $alert->status === 'RESOLVED' ? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' : '' }}">
                                        {{ $alert->status }}
                                    </span>
                                </p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    Value {{ $alert->score_at_trigger }} against threshold {{ $alert->threshold_value ?? 'anomaly baseline' }}
                                    &middot; {{ $alert->triggered_at->diffForHumans() }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0">
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
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-gray-500">No alerts yet.</li>
                    @endforelse
                </ul>
            </div>

            <div class="mt-4">
                {{ $alerts->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
