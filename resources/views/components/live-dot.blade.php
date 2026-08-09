@props(['label' => 'Live'])

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5']) }}>
    <span class="relative flex h-2 w-2">
        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
    </span>
    <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">{{ $label }}</span>
</span>
