@props(['responsive' => false])

{{--
    Clarity Pass B4 — the nav sector switcher. Shows only when the user follows more than one
    sector. Picking one pins it (UserDashboardPreference::current_sector_id); the dashboard,
    regions pages and their tab strips then scope to that sector's indices via
    App\Support\IndexCoverage. "All sectors" clears the pin.
--}}
@php
    $navSectors = Auth::user()->sectorSubscriptions()->with('sector')->get()
        ->pluck('sector')->filter()->sortBy('sort_order')->values();

    $rawPin = $navSectors->count() > 1
        ? Auth::user()->getOrCreateDashboardPreference()->current_sector_id
        : null;
    $pinnedId = $rawPin !== null ? (int) $rawPin : null;
    $pinned = $pinnedId !== null ? $navSectors->firstWhere('sector_id', $pinnedId) : null;
@endphp

@if ($navSectors->count() > 1)
    @php
        // [id, label, isActive] rows — "All sectors" (id '') first, then each followed sector.
        $rows = collect([['', 'All sectors', $pinnedId === null]])->concat(
            $navSectors->map(fn ($s) => [(string) $s->sector_id, $s->name, $pinnedId === $s->sector_id])
        );
    @endphp

    @if ($responsive)
        <div class="border-t border-gray-200 pt-2 dark:border-gray-600">
            <div class="px-4 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Focus</div>
            <form method="POST" action="{{ route('preferences.sector') }}" class="px-2 pb-1">
                @csrf
                @foreach ($rows as [$id, $label, $isActive])
                    <button type="submit" name="sector_id" value="{{ $id }}"
                            class="flex w-full items-center gap-2 rounded px-2 py-2 text-start text-base {{ $isActive ? 'font-semibold text-primary' : 'text-gray-600 dark:text-gray-300' }} hover:bg-gray-50 dark:hover:bg-gray-700">
                        <span class="h-1.5 w-1.5 rounded-full {{ $isActive ? 'bg-primary' : 'bg-transparent' }}"></span>
                        {{ $label }}
                    </button>
                @endforeach
            </form>
        </div>
    @else
        <x-dropdown align="left" width="w-60">
            <x-slot name="trigger">
                <button type="button" class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none">
                    <span class="h-1.5 w-1.5 rounded-full {{ $pinned ? 'bg-primary' : 'bg-gray-300 dark:bg-gray-600' }}"></span>
                    {{ $pinned?->short_name ?? 'All sectors' }}
                    <svg class="h-4 w-4 fill-current text-gray-400" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                </button>
            </x-slot>
            <x-slot name="content">
                <div class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Focus the workspace</div>
                <form method="POST" action="{{ route('preferences.sector') }}">
                    @csrf
                    @foreach ($rows as [$id, $label, $isActive])
                        <button type="submit" name="sector_id" value="{{ $id }}"
                                class="flex w-full items-center gap-2 px-4 py-2 text-start text-sm {{ $isActive ? 'font-semibold text-primary' : 'text-gray-700 dark:text-gray-300' }} hover:bg-gray-100 dark:hover:bg-gray-700">
                            <span class="h-1.5 w-1.5 rounded-full {{ $isActive ? 'bg-primary' : 'bg-transparent' }}"></span>
                            {{ $label }}
                        </button>
                    @endforeach
                </form>
            </x-slot>
        </x-dropdown>
    @endif
@endif
