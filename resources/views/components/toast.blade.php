@php
    // Breeze's stock partials pass short status *codes*, not messages (e.g. 'profile-updated')
    // — translate the known ones so the toast never shows a raw code to the user.
    $statusCodes = [
        'profile-updated' => 'Profile updated.',
        'notifications-updated' => 'Notification preferences updated.',
        'password-updated' => 'Password updated.',
        'verification-link-sent' => 'Verification link sent — check your inbox.',
    ];

    $message = null;
    $type = 'success';

    if (session('error')) {
        $message = session('error');
        $type = 'error';
    } elseif (session('success')) {
        $message = session('success');
    } elseif (session('status')) {
        $raw = session('status');
        $message = $statusCodes[$raw] ?? $raw;
    }
@endphp

@if ($message)
    <div
        x-data="{ show: true }"
        x-show="show"
        x-init="setTimeout(() => show = false, 5000)"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-x-4"
        x-transition:enter-end="opacity-100 translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed top-4 right-4 z-50 max-w-sm w-[calc(100%-2rem)] sm:w-full"
    >
        <div class="flex items-start gap-3 p-4 rounded-xl shadow-lg border border-l-4 bg-white dark:bg-slate-800 border-slate-200 dark:border-slate-700
            {{ $type === 'error' ? 'border-l-red-500' : 'border-l-gano-500' }}">
            <div class="flex-shrink-0 mt-0.5 {{ $type === 'error' ? 'text-red-500' : 'text-gano-600 dark:text-gano-400' }}">
                @if ($type === 'error')
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                @else
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                @endif
            </div>
            <p class="text-sm text-slate-700 dark:text-slate-200 flex-1">{{ $message }}</p>
            <button @click="show = false" class="flex-shrink-0 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
    </div>
@endif
