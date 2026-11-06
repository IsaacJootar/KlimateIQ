@props(['active' => false, 'icon' => null])

@php
$classes = ($active ?? false) ? 'sidebar-link sidebar-link-active' : 'sidebar-link';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    @if ($icon)
        <x-dynamic-component :component="'heroicon-o-'.$icon" class="sidebar-icon" />
    @endif
    <span class="truncate">{{ $slot }}</span>
</a>
