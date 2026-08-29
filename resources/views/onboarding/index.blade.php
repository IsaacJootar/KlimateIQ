@php
    use Illuminate\Support\Js;

    $defaultSectorIds = $sectors->where('is_default', true)->pluck('sector_id')->map('strval')->values();
    $sectorIndexMap = $sectors->mapWithKeys(fn ($s) => [
        (string) $s->sector_id => $s->indices->pluck('index_id')->map('strval')->values(),
    ]);
    $regionStateLabel = $userState && str_starts_with($userState, 'FCT') ? 'FCT' : $userState;
@endphp

<x-onboarding-layout>
    <div
        x-data="{
            step: 1,
            sectorIds: {{ Js::from($defaultSectorIds) }},
            indexIds: [],
            regionScope: 'all',
            regionSearch: '',
            sectorIndexMap: {{ Js::from($sectorIndexMap) }},
            sectorPicked(id) {
                return this.sectorIds.includes(String(id));
            },
            toStep2() {
                // Re-seed the index picks from the chosen sectors whenever we arrive here.
                const ids = new Set();
                this.sectorIds.forEach(sid => (this.sectorIndexMap[sid] || []).forEach(ix => ids.add(ix)));
                this.indexIds = [...ids];
                this.step = 2;
            },
            matchesSearch(haystack) {
                return this.regionSearch === '' || haystack.includes(this.regionSearch.toLowerCase());
            },
        }"
    >
        <p class="text-sm font-semibold text-primary">Set up your workspace</p>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">
            <span x-show="step === 1">What do you monitor?</span>
            <span x-show="step === 2" x-cloak>Which risks do you monitor?</span>
            <span x-show="step === 3" x-cloak>Which areas do you monitor?</span>
        </h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Step <span x-text="step"></span> of 3 — you can change all of this later from the Workspace page.
        </p>

        <form method="POST" action="{{ route('onboarding.store') }}" class="mt-8">
            @csrf

            {{-- Step 1 — sectors --}}
            <div x-show="step === 1" class="space-y-3">
                @foreach ($sectors as $sector)
                    <label class="flex gap-3 rounded-xl border border-gray-200 dark:border-gray-700 p-4 cursor-pointer transition hover:border-primary/60 has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                        <input type="checkbox" name="sector_ids[]" value="{{ $sector->sector_id }}"
                               class="mt-0.5 rounded" x-model="sectorIds">
                        <span>
                            <span class="block font-semibold text-gray-900 dark:text-white">{{ $sector->name }}</span>
                            <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $sector->description }}</span>
                            @if ($sector->indices->isNotEmpty())
                                <span class="mt-1 block text-xs text-gray-400">
                                    {{ $sector->indices->pluck('name')->map(fn ($n) => str_replace(' Index', '', $n))->join(' · ') }}
                                </span>
                            @endif
                        </span>
                    </label>
                @endforeach

                <div class="flex items-center justify-between pt-4">
                    <button type="button" class="text-sm text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                            @click="$refs.skip.submit()">
                        Skip setup — I'll do this later
                    </button>
                    <button type="button" class="btn-primary"
                            :disabled="sectorIds.length === 0"
                            @click="toStep2()">
                        Continue
                    </button>
                </div>
            </div>

            {{-- Step 2 — indices within the chosen sectors. Every group is rendered up front;
                 Alpine just shows the ones whose sector is picked and tracks the ticked state. --}}
            <div x-show="step === 2" x-cloak class="space-y-5">
                @foreach ($sectors as $sector)
                    <div x-show="sectorPicked({{ $sector->sector_id }})" x-cloak>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $sector->name }}</p>
                        <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2">
                            @foreach ($sector->indices as $index)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="index_ids[]" value="{{ $index->index_id }}" class="rounded"
                                           x-model="indexIds" :disabled="! sectorPicked({{ $sector->sector_id }})">
                                    {{ str_replace(' Index', '', $index->name) }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <p class="text-xs text-gray-400" x-show="indexIds.length === 0">
                    Nothing selected — your dashboard will show every index. That's fine too.
                </p>

                <div class="flex items-center justify-between pt-4">
                    <button type="button" class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300" @click="step = 1">Back</button>
                    <button type="button" class="btn-primary" @click="step = 3">Continue</button>
                </div>
            </div>

            {{-- Step 3 — regions --}}
            <div x-show="step === 3" x-cloak class="space-y-3">
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="region_scope" value="all" x-model="regionScope">
                    All of Nigeria <span class="text-gray-400">— every active region</span>
                </label>
                @if ($regionStateLabel)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="region_scope" value="state" x-model="regionScope">
                        Just {{ $regionStateLabel }} <span class="text-gray-400">— the state on your profile</span>
                    </label>
                @endif
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="region_scope" value="specific" x-model="regionScope">
                    Choose specific LGAs
                </label>

                <div x-show="regionScope === 'specific'" x-cloak class="pl-6 pt-1">
                    <x-text-input type="search" x-model="regionSearch" placeholder="Search by LGA or state name..." class="w-full mb-3" />
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-72 overflow-y-auto pr-1">
                        @foreach ($regions as $region)
                            <label class="flex items-center gap-2 text-sm"
                                   x-show="matchesSearch('{{ addslashes(strtolower($region->name.' '.$region->state)) }}')">
                                <input type="checkbox" name="region_ids[]" value="{{ $region->region_id }}" class="rounded"
                                       :disabled="regionScope !== 'specific'">
                                {{ $region->name }}, {{ $region->state }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex items-center justify-between pt-4">
                    <button type="button" class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300" @click="step = 2">Back</button>
                    <x-loading-button class="btn-primary" loading-text="Setting up…">Finish setup</x-loading-button>
                </div>
            </div>
        </form>

        <form method="POST" action="{{ route('onboarding.skip') }}" x-ref="skip" class="hidden">
            @csrf
        </form>
    </div>
</x-onboarding-layout>
