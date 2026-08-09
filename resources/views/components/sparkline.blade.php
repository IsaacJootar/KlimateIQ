@props(['values' => [], 'band' => 'none', 'width' => 72, 'height' => 24])

@php
    $points = collect($values)->filter(fn ($v) => $v !== null)->values();
    $strokeColor = ['green' => '#059669', 'amber' => '#D97706', 'red' => '#DC2626', 'none' => '#94A3B8'][$band] ?? '#94A3B8';
@endphp

@if ($points->count() < 2)
    <span class="text-xs text-slate-400" title="Not enough history yet for a trend line">&mdash;</span>
@else
    @php
        $min = $points->min();
        $max = $points->max();
        $range = ($max - $min) > 0 ? ($max - $min) : 1;
        $stepX = $width / ($points->count() - 1);
        $pad = 3;

        $coords = $points->values()->map(function ($v, $i) use ($stepX, $height, $min, $range, $pad) {
            $x = round($i * $stepX, 1);
            $y = round($pad + ($height - 2 * $pad) * (1 - ($v - $min) / $range), 1);

            return "{$x},{$y}";
        })->implode(' ');

        $lastIndex = $points->count() - 1;
        $lastX = round($lastIndex * $stepX, 1);
        $lastY = round($pad + ($height - 2 * $pad) * (1 - ($points->last() - $min) / $range), 1);
    @endphp
    <svg viewBox="0 0 {{ $width }} {{ $height }}" width="{{ $width }}" height="{{ $height }}" class="inline-block align-middle" aria-hidden="true">
        <polyline points="{{ $coords }}" fill="none" stroke="{{ $strokeColor }}" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" />
        <circle cx="{{ $lastX }}" cy="{{ $lastY }}" r="2" fill="{{ $strokeColor }}" />
    </svg>
@endif
