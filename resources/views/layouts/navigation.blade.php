@php
    $isEnterprise = Auth::user()->agency?->portfolio_type !== null;
    $unreadCount = Auth::user()->unreadNotifications()->count();

    if ($isEnterprise) {
        $navItems = [
            ['label' => 'Dashboard', 'icon' => 'home', 'href' => route('dashboard'), 'active' => request()->routeIs('dashboard')],
            ['label' => 'Asset Risk', 'icon' => 'building-office-2', 'href' => route('portfolios.index'), 'active' => request()->routeIs('portfolios.*') && ! request()->routeIs('portfolios.stress-test')],
            ['label' => 'Portfolio Exposure', 'shortLabel' => 'Exposure', 'icon' => 'chart-bar', 'href' => route('exposure.index'), 'active' => request()->routeIs('exposure.*')],
            ['label' => 'Signals & Alerts', 'icon' => 'bell-alert', 'href' => route('alerts.index'), 'active' => request()->routeIs('alerts.*')],
            ['label' => 'Scenario Testing', 'icon' => 'beaker', 'href' => route('scenario-testing.index'), 'active' => request()->routeIs('scenario-testing.*') || request()->routeIs('portfolios.stress-test')],
            ['label' => 'Thresholds', 'icon' => 'adjustments-horizontal', 'href' => route('thresholds.index'), 'active' => request()->routeIs('thresholds.*')],
            ['label' => 'Reports', 'icon' => 'document-text', 'href' => route('reports.index'), 'active' => request()->routeIs('reports.*')],
            ['label' => 'API Access', 'icon' => 'key', 'href' => route('api-access.index'), 'active' => request()->routeIs('api-access.*')],
        ];
    } else {
        $navItems = [
            ['label' => 'Dashboard', 'icon' => 'home', 'href' => route('dashboard'), 'active' => request()->routeIs('dashboard')],
            ['label' => 'Regions', 'icon' => 'map', 'href' => route('regions.index'), 'active' => request()->routeIs('regions.*')],
            ['label' => 'Workspace', 'icon' => 'squares-2x2', 'href' => route('coverage.edit'), 'active' => request()->routeIs('coverage.*')],
            ['label' => 'Alerts', 'icon' => 'bell-alert', 'href' => route('alerts.index'), 'active' => request()->routeIs('alerts.*')],
            ['label' => 'Thresholds', 'icon' => 'adjustments-horizontal', 'href' => route('thresholds.index'), 'active' => request()->routeIs('thresholds.*')],
            ['label' => 'Saved Views', 'icon' => 'bookmark', 'href' => route('saved-views.index'), 'active' => request()->routeIs('saved-views.*')],
            ['label' => 'Reports', 'icon' => 'document-text', 'href' => route('reports.index'), 'active' => request()->routeIs('reports.*')],
        ];
    }

    if (Auth::user()->hasFederalOversight()) {
        $navItems[] = ['label' => 'Platform Overview', 'icon' => 'globe-alt', 'href' => route('overview.index'), 'active' => request()->routeIs('overview.*')];
    }

    $navItems[] = ['label' => 'Notifications', 'icon' => 'bell', 'href' => route('notifications.index'), 'active' => request()->routeIs('notifications.*'), 'badge' => $unreadCount];

    // Mobile is a bottom tab bar (reference app's pattern), not a slide-over drawer: the first
    // 3 items get a permanent tab, everything else lives behind "More".
    $mobilePrimary = array_slice($navItems, 0, 3);
    $mobileRest = array_slice($navItems, 3);
    $moreIsActive = collect($mobileRest)->contains(fn ($item) => $item['active']);
@endphp

<div x-data="{ moreOpen: false }">

    <!-- Desktop sidebar -->
    <aside class="app-sidebar hidden sm:flex sm:w-64 sm:shrink-0 sm:flex-col sm:h-screen sm:sticky sm:top-0 overflow-y-auto">
        @include('layouts.sidebar-content', ['navItems' => $navItems, 'isEnterprise' => $isEnterprise])
    </aside>

    <!-- Mobile top bar -->
    <div class="sm:hidden sticky top-0 z-30 flex items-center justify-between bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700 px-4 h-16">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
            <x-application-logo class="block h-8 w-auto text-gano-600 dark:text-gano-400 flex-shrink-0" />
            <span class="flex flex-col leading-none">
                <span class="text-lg font-bold text-gray-800 dark:text-gray-200">KlimateIQ</span>
                <span class="text-[7px] font-semibold uppercase tracking-wide text-gano-600 dark:text-gano-400 mt-0.5 whitespace-nowrap">Climate Risk Intelligence</span>
            </span>
        </a>
        <x-theme-toggle class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" />
    </div>

    <!-- Mobile bottom tab bar -->
    <nav class="sm:hidden fixed inset-x-0 bottom-0 z-40 app-sidebar border-t border-white/10 flex items-stretch" style="padding-bottom: env(safe-area-inset-bottom)">
        @foreach ($mobilePrimary as $item)
            <a href="{{ $item['href'] }}" class="flex-1 flex flex-col items-center justify-center gap-0.5 py-2.5 text-[11px] font-medium {{ $item['active'] ? 'text-white' : 'text-slate-400' }}">
                <x-dynamic-component :component="'heroicon-o-'.$item['icon']" class="h-5 w-5" />
                <span class="truncate max-w-[72px]">{{ __($item['shortLabel'] ?? $item['label']) }}</span>
            </a>
        @endforeach
        <button type="button" @click="moreOpen = true" class="flex-1 flex flex-col items-center justify-center gap-0.5 py-2.5 text-[11px] font-medium {{ $moreIsActive ? 'text-white' : 'text-slate-400' }}">
            <x-heroicon-o-ellipsis-horizontal-circle class="h-5 w-5" />
            <span>More</span>
        </button>
    </nav>

    <!-- Mobile "More" sheet -->
    <div x-show="moreOpen" x-cloak class="sm:hidden fixed inset-0 z-50" role="dialog" aria-modal="true">
        <div x-show="moreOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-gray-900/50" @click="moreOpen = false"></div>
        <div x-show="moreOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
             class="fixed inset-x-0 bottom-0 top-12 bg-white dark:bg-gray-800 rounded-t-2xl shadow-xl overflow-y-auto">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200">More</h2>
                <button type="button" @click="moreOpen = false" aria-label="Close" class="p-1 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($mobileRest as $item)
                    <a href="{{ $item['href'] }}" class="flex items-center gap-3 px-5 py-3.5 {{ $item['active'] ? 'text-gano-700 dark:text-gano-300 font-semibold' : 'text-gray-700 dark:text-gray-300' }}">
                        <x-dynamic-component :component="'heroicon-o-'.$item['icon']" class="h-5 w-5 shrink-0 {{ $item['active'] ? 'text-gano-600 dark:text-gano-400' : 'text-gray-400' }}" />
                        <span class="flex-1">{{ __($item['label']) }}</span>
                        @if (($item['badge'] ?? 0) > 0)
                            <span class="inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold rounded-full bg-gano-600 text-white">{{ $item['badge'] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</div>
