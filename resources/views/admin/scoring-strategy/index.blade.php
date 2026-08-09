<x-app-layout title="Scoring Strategy">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Scoring Strategy') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Per-region control over which scoring engine calculates its scores — the transparent
            weighted formula (default), or a trained model once one exists.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="rounded-xl border {{ $anyModelAvailable ? 'border-emerald-300 dark:border-emerald-700 bg-emerald-50 dark:bg-emerald-900/20' : 'border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20' }} p-4">
                <p class="text-sm font-semibold {{ $anyModelAvailable ? 'text-emerald-800 dark:text-emerald-300' : 'text-amber-800 dark:text-amber-300' }} mb-2">
                    @if ($anyModelAvailable)
                        A trained model exists for at least one index.
                    @else
                        No trained model exists for any index yet.
                    @endif
                </p>
                <p class="text-xs {{ $anyModelAvailable ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400' }} mb-3">
                    Setting a region to "Trained model" below only takes effect for an index that actually has a
                    model file — every index without one keeps using the formula strategy automatically, silently
                    and safely, no matter what's selected here. Right now, that also means: even for an index
                    that does have a model file, the code that turns it into a score
                    (<code class="text-[11px]">TrainedModelScoringStrategy::predict()</code>) hasn't been written
                    yet — so today, every region's scores are actually computed by the formula strategy
                    regardless of this setting. This page exists to make that override real and inspectable once
                    that work is done, not to claim it's live already.
                </p>
                <ul class="text-xs space-y-1">
                    @foreach ($modelAvailability as $code => $available)
                        <li class="flex items-center gap-2">
                            <span class="risk-badge {{ $available ? 'risk-badge-green' : 'risk-badge-none' }}">
                                {{ $available ? 'model found' : 'no model' }}
                            </span>
                            {{ $code }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="section-card overflow-hidden">
                <div class="section-card-header">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Active regions</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3">Region</th>
                                <th class="px-4 py-3">Preference</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($regions as $region)
                                <tr>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $region->name }}, {{ $region->state }}</td>
                                    <td class="px-4 py-3" colspan="2">
                                        <form method="POST" action="{{ route('admin.scoring-strategy.update', $region) }}" class="flex items-center gap-2">
                                            @csrf @method('PATCH')
                                            <x-select-input name="preferred_scoring_strategy" class="!py-1.5 text-sm max-w-xs">
                                                <option value="" @selected($region->preferred_scoring_strategy === null)>Formula (platform default)</option>
                                                <option value="formula" @selected($region->preferred_scoring_strategy === 'formula')>Formula (explicit)</option>
                                                <option value="trained_model" @selected($region->preferred_scoring_strategy === 'trained_model')>Trained model</option>
                                            </x-select-input>
                                            <x-loading-button class="btn-secondary flex-shrink-0" loading-text="Saving…">Save</x-loading-button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-4 py-8 text-center text-sm text-gray-500">No active regions yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
