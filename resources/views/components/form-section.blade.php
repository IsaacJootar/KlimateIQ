@props(['title', 'description' => null, 'last' => false])

{{--
    A form should never read as one long unbroken list of fields. Each group states what it is
    and why it's being asked, separated from the next group by a hairline rather than by
    whitespace alone — that's what actually keeps a multi-field form legible.
--}}
<section {{ $attributes->merge(['class' => $last ? '' : 'border-b border-slate-100 dark:border-slate-700 pb-6 mb-6']) }}>
    <div class="mb-4">
        <h2 class="text-sm font-bold text-slate-900 dark:text-white">{{ $title }}</h2>
        @if ($description)
            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ $description }}</p>
        @endif
    </div>
    <div class="space-y-5">
        {{ $slot }}
    </div>
</section>
