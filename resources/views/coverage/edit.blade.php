@php
    use Illuminate\Support\Js;

    $sectorIndexMap = $sectors->mapWithKeys(fn ($s) => [
        (string) $s->sector_id => $s->indices->pluck('index_id')->map('strval')->values(),
    ]);
@endphp

<x-app-layout title="Workspace">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Workspace') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Pick the sectors you monitor — your dashboard and alerts cover every risk index in them. Narrow the
            regions if you want. Change any of this whenever you like.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8"
             x-data="{
                 sectorIds: {{ Js::from(array_map('strval', $subscribedSectorIds)) }},
                 keptIndexIds: [],
                 regionScope: '{{ $subscribedRegionIds ? 'specific' : 'all' }}',
                 regionSearch: '',
                 sectorIndexMap: {{ Js::from($sectorIndexMap) }},
                 init() {
                     const stored = {{ Js::from(array_map('strval', $refinedIndexIds)) }};
                     this.keptIndexIds = stored.length ? stored : this.inScopeIndexIds();
                     this.$watch('sectorIds', () => { this.keptIndexIds = this.inScopeIndexIds(); });
                 },
                 inScopeIndexIds() {
                     const s = new Set();
                     this.sectorIds.forEach(id => (this.sectorIndexMap[id] || []).forEach(ix => s.add(ix)));
                     return [...s];
                 },
                 sectorPicked(id) {
                     return this.sectorIds.includes(String(id));
                 },
                 matchesSearch(haystack) {
                     return this.regionSearch === '' || haystack.includes(this.regionSearch.toLowerCase());
                 },
             }">
            <form method="POST" action="{{ route('coverage.update') }}" class="section-card p-6">
                @csrf
                @method('PUT')

                <x-form-section title="Sectors" description="What do you monitor? Your dashboard and alerts scope to every risk index in the sectors you pick.">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach ($sectors as $sector)
                            <label class="flex gap-3 rounded-lg border border-slate-200 dark:border-slate-700 p-3 cursor-pointer hover:border-primary/60 has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                <input type="checkbox" name="sector_ids[]" value="{{ $sector->sector_id }}"
                                       class="mt-0.5 rounded" x-model="sectorIds">
                                <span class="text-sm">
                                    <span class="font-semibold text-slate-900 dark:text-white block">{{ $sector->name }}</span>
                                    <span class="text-slate-500 dark:text-slate-400">{{ $sector->promise }}</span>
                                    @if ($sector->indices->isNotEmpty())
                                        <span class="mt-1 block text-xs text-slate-400">{{ $sector->indices->pluck('name')->map(fn ($n) => str_replace(' Index', '', $n))->join(' · ') }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                </x-form-section>

                <x-form-section title="Which risks show on your dashboard"
                    description="Everything your sectors cover is on. Untick any you don't want to see — you can only narrow within your sectors here.">

                    <p class="text-sm text-slate-500 dark:text-slate-400" x-show="! sectorIds.length" x-cloak>
                        Pick a sector above and its risk indices appear here.
                    </p>

                    <div class="space-y-4" x-show="sectorIds.length" x-cloak>
                        @foreach ($sectors as $sector)
                            <div x-show="sectorPicked({{ $sector->sector_id }})" x-cloak>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ $sector->short_name }}</p>
                                <div class="mt-1.5 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1.5">
                                    @foreach ($sector->indices as $idx)
                                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                            <input type="checkbox" name="index_ids[]" value="{{ $idx->index_id }}" class="rounded"
                                                   x-model="keptIndexIds">
                                            {{ str_replace(' Index', '', $idx->name) }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-form-section>

                <x-form-section title="Regions" last="true" description="Optional. Leave on “All” to see every active region, or narrow to the LGAs you’re responsible for. Adding a brand-new region kicks off its first data pull right away.">
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="region_scope" value="all" x-model="regionScope">
                            All regions <span class="text-slate-400">— no filtering</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="region_scope" value="specific" x-model="regionScope">
                            Only specific regions
                        </label>
                    </div>
                    <div x-show="regionScope === 'specific'" x-cloak class="pl-6">
                        <x-text-input type="search" x-model="regionSearch" placeholder="Search by LGA or state name..." class="w-full mb-3" />
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-80 overflow-y-auto pr-1">
                            @foreach ($regions as $region)
                                <label class="flex items-center gap-2 text-sm"
                                    x-show="matchesSearch('{{ addslashes(strtolower($region->name.' '.$region->state)) }}')">
                                    <input type="checkbox" name="region_ids[]" value="{{ $region->region_id }}" class="rounded"
                                        @checked(in_array($region->region_id, $subscribedRegionIds))
                                        :disabled="regionScope !== 'specific'">
                                    {{ $region->name }}, {{ $region->state }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                </x-form-section>

                <x-loading-button class="btn-primary w-full sm:w-auto" loading-text="Saving…">Save workspace</x-loading-button>
            </form>
        </div>
        </div>
    </div>
</x-app-layout>
