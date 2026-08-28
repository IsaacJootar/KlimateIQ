@php
    $user = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $user?->theme === 'dark' ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ __('Set up your workspace') }} · KlimateIQ</title>

        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            <header class="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                <div class="max-w-3xl mx-auto px-4 sm:px-6 py-4 flex items-center justify-between">
                    <span class="flex items-center gap-2">
                        <x-application-logo class="w-8 h-8 text-gano-600 dark:text-gano-400 flex-shrink-0" />
                        <span class="flex flex-col leading-none">
                            <span class="text-base font-bold text-gray-800 dark:text-gray-200">KlimateIQ</span>
                            <span class="text-[7px] font-semibold uppercase tracking-wide text-gano-600 dark:text-gano-400 mt-0.5">Climate Intelligence</span>
                        </span>
                    </span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">Sign out</button>
                    </form>
                </div>
            </header>

            <main class="max-w-3xl mx-auto px-4 sm:px-6 py-10">
                {{ $slot }}
            </main>
        </div>

        <x-toast />

        @livewireScripts
    </body>
</html>
