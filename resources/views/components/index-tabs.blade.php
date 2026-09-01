@props(['groups', 'active', 'routeName', 'routeParams' => [], 'hideWhenSingle' => false])

{{--
    The index tab strip (Clarity Pass B2). `$groups` comes from App\Support\IndexCoverage::resolve()
    — an ordered list of ['sector' => Sector|null, 'indices' => Collection<ScoringIndex>]. When there
    are two or more groups a small sector name is threaded inline ahead of each group's tabs; the
    pills still flow as one wrapping row, so they fill the width and break where they need to rather
    than one sector per line. With one group it's a plain strip (the label would only echo the page
    header). Every tab links to $routeName with the caller's base params plus ?index=<code>.
    `hideWhenSingle` drops the strip entirely when only one index is available (the Dashboard does
    this — the metric cards already name it).
--}}
@php
    $total = $groups->sum(fn ($g) => $g['indices']->count());
    $showLabels = $groups->count() > 1;
@endphp

@if ($total > 0 && ! ($hideWhenSingle && $total === 1))
    <div class="flex flex-wrap items-center gap-x-2 gap-y-2">
        @foreach ($groups as $group)
            @if ($showLabels)
                <span @class([
                    'whitespace-nowrap text-[0.7rem] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500',
                    'ms-2.5' => ! $loop->first,
                ])>
                    {{ $group['sector']?->short_name ?? 'Other' }}
                </span>
            @endif
            @foreach ($group['indices'] as $idx)
                <a href="{{ route($routeName, $routeParams + ['index' => $idx->code]) }}"
                   @if ($idx->description) title="{{ $idx->description }}" @endif
                   class="pill-tab {{ $idx->index_id === $active->index_id ? 'pill-tab-active' : '' }}">
                    @if ($idx->is_forecast)
                        <svg class="mr-1 -ml-0.5 inline h-3 w-3 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    @endif
                    {{ $idx->name }}
                </a>
            @endforeach
        @endforeach
    </div>
@endif
