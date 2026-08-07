<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100 dark:bg-gray-900">
            <div class="flex items-center gap-2">
                <a href="/">
                    <x-application-logo class="w-12 h-12 text-gano-600 dark:text-gano-400" />
                </a>
                <span class="text-xl font-bold text-gray-800 dark:text-gray-200">Gano.ai</span>
            </div>

            @php
                // Tailwind's scanner needs literal class strings, not an interpolated one, so
                // list every width this layout actually uses rather than building the class
                // dynamically.
                $widthClass = match ($maxWidth) {
                    'xl' => 'sm:max-w-xl',
                    'lg' => 'sm:max-w-lg',
                    default => 'sm:max-w-md',
                };
            @endphp
            <div class="w-full {{ $widthClass }} mt-6 px-6 py-4 section-card">
                {{ $slot }}
            </div>
        </div>

        @livewireScripts
    </body>
</html>
