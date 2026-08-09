@props(['type' => 'submit', 'loadingText' => 'Working…'])

{{--
    Self-contained: listens for its own closest <form>'s submit event rather than requiring the
    form to declare x-data itself. This is deliberate — every "Save"/"Delete"/"Issue" button in
    this app must show a loading state so a slow request never looks like a dead click, and the
    easiest way to guarantee that isn't broken by a future page is to make it automatic here
    rather than something every new form has to remember to wire up.

    Listening for the form's `submit` event (not the button's `click`) matters: if the form has
    unmet HTML5 validation (a required field left blank), `submit` never fires, so this never
    gets stuck showing a spinner over a button that didn't actually do anything.
--}}
<button
    type="{{ $type }}"
    x-data="{ loading: false }"
    x-init="$el.closest('form')?.addEventListener('submit', () => loading = true)"
    x-bind:disabled="loading"
    {{ $attributes }}
>
    <span x-show="! loading">{{ $slot }}</span>
    <span x-show="loading" x-cloak class="inline-flex items-center gap-1.5">
        <svg class="animate-spin h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        {{ $loadingText }}
    </span>
</button>
