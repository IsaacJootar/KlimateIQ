@props(['status'])

@php
    $s = $status instanceof \App\Support\CalibrationStatus
        ? $status
        : \App\Support\CalibrationStatus::tryFrom($status ?? 'placeholder') ?? \App\Support\CalibrationStatus::Placeholder;
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2 py-0.5 text-[0.65rem] font-semibold '.$s->chipClasses()]) }}>
    {{ $s->label() }}
</span>
