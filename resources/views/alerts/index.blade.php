<x-app-layout title="Alerts">
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
            <div class="section-card overflow-hidden">
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($alerts as $alert)
                        <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">
                                    {{ $alert->index?->name ?? $alert->signalType?->name }}
                                    <span class="font-normal text-slate-400">in</span>
                                    {{ $alert->region->name }}
                                    @if ($alert->is_forecast)
                                        <span class="inline-flex ms-1 items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-sky-100 text-sky-700 dark:bg-sky-900 dark:text-sky-200">
                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                            Forecast@if ($alert->forecast_probability !== null) &middot; {{ round((float) $alert->forecast_probability * 100) }}%@endif
                                        </span>
                                    @endif
                                    <span class="inline-flex ms-1 px-2 py-0.5 rounded-full text-xs font-semibold
                                        {{ $alert->status === 'OPEN' ? 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200' : '' }}
                                        {{ $alert->status === 'ACKNOWLEDGED' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200' : '' }}
                                        {{ $alert->status === 'RESOLVED' ? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' : '' }}">
                                        {{ $alert->status }}
                                    </span>
                                </p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    @if ($alert->forecast_probability !== null)
                                        ≈{{ round((float) $alert->forecast_probability * 100) }}% chance of reaching {{ $alert->threshold_value }}+ within the forecast window
                                        &middot; most-likely peak {{ $alert->score_at_trigger }}
                                        @if ($alert->forecast_target_date) &middot; around {{ $alert->forecast_target_date->format('M j') }}@endif
                                    @elseif ($alert->is_forecast)
                                        Forecast peak {{ $alert->score_at_trigger }}
                                        @if ($alert->threshold_value !== null) past your {{ $alert->threshold_value }} threshold @endif
                                        @if ($alert->forecast_target_date) &middot; projected for {{ $alert->forecast_target_date->format('M j') }}@if ($alert->forecast_lead_days !== null) ({{ $alert->forecast_lead_days }}d out)@endif @endif
                                    @else
                                        Value {{ $alert->score_at_trigger }} against threshold {{ $alert->threshold_value ?? 'anomaly baseline' }}
                                    @endif
                                    &middot; {{ $alert->triggered_at->diffForHumans() }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                @if ($alert->status === 'OPEN')
                                    <form method="POST" action="{{ route('alerts.acknowledge', $alert) }}" class="inline">
                                        @csrf @method('PATCH')
                                        <x-loading-button class="btn-secondary" loading-text="Acknowledging…">Acknowledge</x-loading-button>
                                    </form>
                                @endif
                                @if ($alert->status !== 'RESOLVED')
                                    <form method="POST" action="{{ route('alerts.resolve', $alert) }}" class="inline">
                                        @csrf @method('PATCH')
                                        <x-loading-button class="btn-primary" loading-text="Resolving…">Resolve</x-loading-button>
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
