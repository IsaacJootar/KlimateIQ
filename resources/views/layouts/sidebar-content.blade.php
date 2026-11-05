@php
    $isEnterprise = Auth::user()->agency?->portfolio_type !== null;
    $unreadCount = Auth::user()->unreadNotifications()->count();
@endphp

<div class="flex flex-col h-full">
    <!-- Logo -->
    <a href="{{ route('dashboard') }}" class="flex items-center gap-2 px-4 h-16 border-b border-gray-100 dark:border-gray-700 shrink-0">
        <x-application-logo class="block h-8 w-auto text-gano-600 dark:text-gano-400 flex-shrink-0" />
        <span class="flex flex-col leading-none">
            <span class="text-lg font-bold text-gray-800 dark:text-gray-200">KlimateIQ</span>
            <span class="text-[7px] font-semibold uppercase tracking-wide text-gano-600 dark:text-gano-400 mt-0.5 whitespace-nowrap">Climate Risk Intelligence</span>
        </span>
    </a>

    <!-- Account -->
    <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-gray-100 dark:border-gray-700 shrink-0">
        <x-dropdown align="left" width="56">
            <x-slot name="trigger">
                <button class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 hover:text-primary min-w-0">
                    <span class="truncate max-w-[9rem]">{{ Auth::user()->name }}</span>
                    <svg class="h-4 w-4 fill-current text-gray-400 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                </button>
            </x-slot>
            <x-slot name="content">
                <x-dropdown-link :href="route('profile.edit')">
                    {{ __('Profile') }}
                </x-dropdown-link>
                <x-dropdown-link :href="route('profile.edit').'#alert-channels'">
                    {{ __('Alert Channels (in-app, email, SMS)') }}
                </x-dropdown-link>

                @if (Auth::user()->isPlatformAdmin())
                    <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                    <x-dropdown-link :href="route('admin.users.index')">
                        {{ __('Admin: Users') }}
                    </x-dropdown-link>
                    <x-dropdown-link :href="route('admin.agencies.index')">
                        {{ __('Admin: Agencies') }}
                    </x-dropdown-link>
                    <x-dropdown-link :href="route('admin.pipeline.index')">
                        {{ __('Admin: Pipeline Health') }}
                    </x-dropdown-link>
                    <x-dropdown-link :href="route('admin.scoring.index')">
                        {{ __('Admin: Index & Scoring Config') }}
                    </x-dropdown-link>
                    <x-dropdown-link :href="route('admin.actions.index')">
                        {{ __('Admin: Recommended Actions') }}
                    </x-dropdown-link>
                    <x-dropdown-link :href="route('admin.scoring-strategy.index')">
                        {{ __('Admin: Scoring Strategy') }}
                    </x-dropdown-link>
                    <x-dropdown-link :href="route('admin.api-tokens.index')">
                        {{ __('Admin: API Tokens') }}
                    </x-dropdown-link>
                    <x-dropdown-link :href="route('admin.settings.index')">
                        {{ __('Admin: Platform Settings') }}
                    </x-dropdown-link>
                @endif

                <!-- Authentication -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-dropdown-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-dropdown-link>
                </form>
            </x-slot>
        </x-dropdown>

        <x-theme-toggle class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 shrink-0" />
    </div>

    @unless ($isEnterprise)
        <x-sector-switcher responsive />
    @endunless

    <!-- Navigation Links -->
    <nav class="flex-1 overflow-y-auto py-2 space-y-1">
        <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
            {{ __('Dashboard') }}
        </x-responsive-nav-link>
        @if ($isEnterprise)
            <x-responsive-nav-link :href="route('portfolios.index')" :active="request()->routeIs('portfolios.*')">
                {{ __('Portfolios') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('exposure.index')" :active="request()->routeIs('exposure.*')">
                {{ __('Exposure') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('alerts.index')" :active="request()->routeIs('alerts.*')">
                {{ __('Alerts') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('thresholds.index')" :active="request()->routeIs('thresholds.*')">
                {{ __('Thresholds') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('reports.index')" :active="request()->routeIs('reports.*')">
                {{ __('Reports') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('api-access.index')" :active="request()->routeIs('api-access.*')">
                {{ __('API Access') }}
            </x-responsive-nav-link>
        @else
            <x-responsive-nav-link :href="route('regions.index')" :active="request()->routeIs('regions.*')">
                {{ __('Regions') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('coverage.edit')" :active="request()->routeIs('coverage.*')">
                {{ __('Workspace') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('alerts.index')" :active="request()->routeIs('alerts.*')">
                {{ __('Alerts') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('thresholds.index')" :active="request()->routeIs('thresholds.*')">
                {{ __('Thresholds') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('saved-views.index')" :active="request()->routeIs('saved-views.*')">
                {{ __('Saved Views') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('reports.index')" :active="request()->routeIs('reports.*')">
                {{ __('Reports') }}
            </x-responsive-nav-link>
        @endif
        @if (Auth::user()->hasFederalOversight())
            <x-responsive-nav-link :href="route('overview.index')" :active="request()->routeIs('overview.*')">
                {{ __('Platform Overview') }}
            </x-responsive-nav-link>
        @endif
        <x-responsive-nav-link :href="route('notifications.index')" :active="request()->routeIs('notifications.*')">
            {{ __('Notifications') }}
            @if ($unreadCount > 0)
                <span class="ms-1 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold rounded-full bg-gano-600 text-white">{{ $unreadCount }}</span>
            @endif
        </x-responsive-nav-link>
    </nav>
</div>
