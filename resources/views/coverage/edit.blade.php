<x-app-layout title="My Coverage">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('My Coverage') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Pick the regions and indices relevant to your role. Your dashboard and region list default to these —
            change this anytime, as often as you like.
        </p>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('coverage.update') }}"
                  x-data="{ regionScope: '{{ $subscribedRegionIds ? 'specific' : 'all' }}', indexScope: '{{ $subscribedIndexIds ? 'specific' : 'all' }}' }"
                  class="section-card p-6">
                @csrf
                @method('PUT')

                <x-form-section title="Regions" description="A state coordinator might cover every region; an LGA officer usually covers just one or a handful.">
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="region_scope" value="all" x-model="regionScope">
                            All regions <span class="text-slate-400">— see everything, no filtering</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="region_scope" value="specific" x-model="regionScope">
                            Only specific regions
                        </label>
                    </div>
                    <div x-show="regionScope === 'specific'" x-cloak class="grid grid-cols-2 sm:grid-cols-3 gap-2 pl-6">
                        @foreach ($regions as $region)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="region_ids[]" value="{{ $region->region_id }}" class="rounded"
                                    @checked(in_array($region->region_id, $subscribedRegionIds))>
                                {{ $region->name }}
                            </label>
                        @endforeach
                    </div>
                </x-form-section>

                <x-form-section title="Indices" last="true" description="Which named risk indices do you want to see and be alerted on? A malaria officer might only need Malaria Risk.">
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="index_scope" value="all" x-model="indexScope">
                            All indices <span class="text-slate-400">— see everything, no filtering</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="index_scope" value="specific" x-model="indexScope">
                            Only specific indices
                        </label>
                    </div>
                    <div x-show="indexScope === 'specific'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-2 pl-6">
                        @foreach ($indices as $idx)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="index_ids[]" value="{{ $idx->index_id }}" class="rounded"
                                    @checked(in_array($idx->index_id, $subscribedIndexIds))>
                                {{ $idx->name }}
                            </label>
                        @endforeach
                    </div>
                </x-form-section>

                <button type="submit" class="btn-primary w-full sm:w-auto">Save coverage</button>
            </form>
        </div>
    </div>
</x-app-layout>
