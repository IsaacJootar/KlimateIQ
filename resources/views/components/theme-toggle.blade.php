@props([])

{{-- Server-rendered, no client JS: the `dark` class on <html> comes straight from the
     authenticated user's theme column, so there's no flash of the wrong theme to prevent. --}}
<form method="POST" action="{{ route('preferences.theme') }}">
    @csrf
    <input type="hidden" name="theme" value="{{ auth()->user()?->theme === 'dark' ? 'light' : 'dark' }}">
    <button type="submit"
            {{ $attributes->merge(['class' => 'p-0.5 rounded transition-colors duration-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gano-500']) }}
            title="{{ auth()->user()?->theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode' }}">
        @if (auth()->user()?->theme === 'dark')
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
            </svg>
        @else
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>
            </svg>
        @endif
        <span class="sr-only">Toggle theme</span>
    </button>
</form>
