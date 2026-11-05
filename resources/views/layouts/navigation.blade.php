{{--
    Enterprise pivot, Module 15 (docs/ENTERPRISE_BUILD_PLAN.md) — left sidebar, replacing the old
    top horizontal nav. The actual link list/account menu lives in layouts.sidebar-content, shared
    between the always-visible desktop sidebar and the mobile slide-over drawer below, so the two
    can never drift out of sync.
--}}
<div x-data="{ mobileOpen: false }">

    <!-- Mobile top bar (sidebar is hidden below sm) -->
    <div class="sm:hidden sticky top-0 z-30 flex items-center justify-between bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700 px-4 h-16">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
            <x-application-logo class="block h-8 w-auto text-gano-600 dark:text-gano-400 flex-shrink-0" />
            <span class="flex flex-col leading-none">
                <span class="text-lg font-bold text-gray-800 dark:text-gray-200">KlimateIQ</span>
                <span class="text-[7px] font-semibold uppercase tracking-wide text-gano-600 dark:text-gano-400 mt-0.5 whitespace-nowrap">Climate Risk Intelligence</span>
            </span>
        </a>
        <button @click="mobileOpen = true" aria-label="Open menu" class="p-2 -me-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
            <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>
    </div>

    <!-- Mobile drawer -->
    <div x-show="mobileOpen" x-cloak class="sm:hidden fixed inset-0 z-40" role="dialog" aria-modal="true">
        <div x-show="mobileOpen"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-gray-900/50" @click="mobileOpen = false"></div>
        <div x-show="mobileOpen"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
             class="fixed inset-y-0 left-0 w-72 max-w-[80vw] bg-white dark:bg-gray-800 shadow-xl overflow-y-auto">
            @include('layouts.sidebar-content')
        </div>
    </div>

    <!-- Desktop sidebar -->
    <aside class="hidden sm:flex sm:w-64 sm:shrink-0 sm:flex-col sm:h-screen sm:sticky sm:top-0 bg-white dark:bg-gray-800 border-r border-gray-100 dark:border-gray-700 overflow-y-auto">
        @include('layouts.sidebar-content')
    </aside>
</div>
