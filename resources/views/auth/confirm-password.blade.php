<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        {{ __('This is a secure area of the application. Please confirm your password before continuing.') }}
    </div>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="flex justify-end mt-4">
            <x-loading-button class="btn-primary text-xs uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-gano-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800" loading-text="Confirming…">
                {{ __('Confirm') }}
            </x-loading-button>
        </div>
    </form>
</x-guest-layout>
