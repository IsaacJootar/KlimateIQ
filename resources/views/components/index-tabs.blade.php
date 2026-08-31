@props(['groups', 'active', 'routeName', 'routeParams' => [], 'hideWhenSingle' => false])

{{--
    The index tab strip (Clarity Pass B2). `$groups` comes from App\Support\IndexCoverage::resolve()
    — an ordered list of ['sector' => Sector|null, 'indices' => Collection<ScoringIndex>]. With two
    or more groups each row of pills is headed by its sector name; with one it's a plain strip (the
    heading would only echo the page header). Every tab links to $routeName with the caller's base
    params plus ?index=<code>. `hideWhenSingle` drops the strip entirely when only one index is
    available (the Dashboard does this — the metric cards already name the index).
--}}
@php
    $total = $groups->sum(fn ($g) => $g['indices']->count());
    $showLabels = $groups->count() > 1;
@endphp

@if ($total > 0 && ! ($hideWhenSingle && $total === 1))
    <div class="space-y-2.5">
        @foreach ($groups as $group)
            <div>
                @if ($showLabels)
                    <p class="mb-1 text-[0.7rem] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                        {{ $group['sector']?->short_name ?? 'Other' }}
                    </p>
                @endif
                <div class="flex flex-wrap gap-2">
                    @foreach ($group['indices'] as $idx)
                        <a href="{{ route($routeName, $routeParams + ['index' => $idx->code]) }}"
                           @if ($idx->description) title="{{ $idx->description }}" @endif
                           class="pill-tab {{ $idx->index_id === $active->index_id ? 'pill-tab-active' : '' }}">
                            {{ $idx->name }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif
