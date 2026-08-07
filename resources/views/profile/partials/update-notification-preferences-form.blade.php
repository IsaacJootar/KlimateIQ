@php
    $preference = $user->getOrCreateDashboardPreference();
    $channels = $preference->alert_channels ?? ['in_app'];
@endphp

<section id="alert-channels">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Alert Channels') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Choose how you want to be notified when one of your thresholds breaches. In-app is always on.') }}
        </p>
    </header>

    <form method="post" action="{{ route('profile.notifications.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div class="space-y-3">
            <label class="flex items-center gap-2 text-sm text-gray-500">
                <input type="checkbox" checked disabled class="rounded">
                In-app notifications (always on)
            </label>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="alert_channels[]" value="email" class="rounded" @checked(in_array('email', $channels))>
                Email
            </label>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="alert_channels[]" value="sms" class="rounded" @checked(in_array('sms', $channels))>
                SMS
            </label>
        </div>

        <div>
            <x-input-label for="phone_number" :value="__('Phone number (for SMS)')" />
            <x-text-input id="phone_number" name="phone_number" type="tel" class="mt-1 block w-full"
                :value="old('phone_number', $user->phone_number)" placeholder="+2348012345678" autocomplete="tel" />
            <x-input-error class="mt-2" :messages="$errors->get('phone_number')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'notifications-updated')
                <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)"
                   class="text-sm text-gray-600 dark:text-gray-400">{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
