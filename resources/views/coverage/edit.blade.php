<x-app-layout title="Workspace">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Workspace') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Start with the sectors that match your work — that picks the risk indices under them. Then narrow the
            regions if you want. Change any of this whenever you like.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('coverage.update') }}"
                  x-data="{
                      sectorIds: {{ Illuminate\Support\Js::from(array_map('strval', $subscribedSectorIds)) }},
                      indexIds: {{ Illuminate\Support\Js::from(array_map('strval', $subscribedIndexIds)) }},
                      indexScope: '{{ $subscribedIndexIds ? 'specific' : 'all' }}',
                      regionScope: '{{ $subscribedRegionIds ? 'specific' : 'all' }}',
                      regionSearch: '',
                      onSectorChange(event, indexIds) {
                          if (event.target.checked) {
                              this.indexScope = 'specific';
                              indexIds.forEach(id => {
                                  if (! this.indexIds.includes(String(id))) this.indexIds.push(String(id));
                              });
                          }
                      },
                      matchesSearch(haystack) {
                          return this.regionSearch === '' || haystack.includes(this.regionSearch.toLowerCase());
                      },
                  }"
                  class="section-card p-6">
                @csrf
                @method('PUT')

                <x-form-section title="Sectors" description="What do you work on? Picking a sector ticks its risk indices below — you can fine-tune from there.">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach ($sectors as $sector)
                            <label class="flex gap-3 rounded-lg border border-slate-200 dark:border-slate-700 p-3 cursor-pointer hover:border-primary/60 has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                <input type="checkbox" name="sector_ids[]" value="{{ $sector->sector_id }}"
                                       class="mt-0.5 rounded"
                                       x-model="sectorIds"
                                       @change="onSectorChange($event, {{ Illuminate\Support\Js::from($sector->indices->pluck('index_id')) }})">
                                <span class="text-sm">
                                    <span class="font-semibold text-slate-900 dark:text-white block">{{ $sector->name }}</span>
                                    <span class="text-slate-500 dark:text-slate-400">{{ $sector->description }}</span>
                                    @if ($sector->indices->isNotEmpty())
                                        <span class="mt-1 block text-xs text-slate-400">{{ $sector->indices->pluck('name')->map(fn ($n) => str_replace(' Index', '', $n))->join(' · ') }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                </x-form-section>

                <x-form-section title="Risk indices" description="The specific indices your dashboard and alerts will use. Leave on “All” to always see every index, including new ones as they’re added.">
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="index_scope" value="all" x-model="indexScope">
                            All indices <span class="text-slate-400">— no filtering</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="index_scope" value="specific" x-model="indexScope">
                            Only the indices I pick
                        </label>
                    </div>
                    <div x-show="indexScope === 'specific'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-2 pl-6">
                        @foreach ($indices as $idx)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="index_ids[]" value="{{ $idx->index_id }}" class="rounded"
                                       x-model="indexIds"
                                       :disabled="indexScope !== 'specific'">
                                {{ $idx->name }}
                            </label>
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
</x-app-layout>
