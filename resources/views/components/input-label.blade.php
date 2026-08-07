@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1']) }}>
    {{ $value ?? $slot }}
</label>
